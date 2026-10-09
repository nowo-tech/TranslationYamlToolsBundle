<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\TextOverride;

use Nowo\TranslationYamlToolsBundle\TextOverride\Entity\TranslationOverride;
use Nowo\TranslationYamlToolsBundle\TextOverride\Html\TranslationOverrideHtmlSanitizerInterface;
use Nowo\TranslationYamlToolsBundle\TextOverride\Repository\TranslationOverrideRepository;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Service\ResetInterface;
use Throwable;

/**
 * Read / write side of operator text overrides ({@see TranslationOverride}).
 *
 * The whole map (usually a few hundred short strings) is cached in a cache pool
 * ({@code overrides.cache_pool}, default {@code cache.app}) for {@code overrides.cache_ttl} seconds
 * and memoised per request; every write through this service drops the cache entry. Any storage
 * failure (schema not migrated yet, cache backend down) means "no overrides": catalogue text is
 * shown and a warning is logged.
 *
 * Markup is sanitized on save and again when the map is loaded, so rows written outside this
 * service (SQL, backup restore, import) cannot inject scripts into messages printed with |raw.
 */
class TranslationOverrides implements TranslationOverrideProviderInterface, ResetInterface
{
    public const CACHE_KEY = 'nowo_translation_yaml_tools.overrides.v1';

    public const DEFAULT_CACHE_TTL = 3600;

    /** @var array<string, array<string, array<string, string>>>|null */
    private ?array $map = null;

    public function __construct(
        private readonly TranslationOverrideRepository $repository,
        private readonly CacheInterface $cache,
        private readonly TranslationOverrideHtmlSanitizerInterface $sanitizer,
        private readonly ?LoggerInterface $logger = null,
        private readonly int $cacheTtl = self::DEFAULT_CACHE_TTL,
    ) {
    }

    public function get(string $locale, string $domain, string $key): ?string
    {
        return $this->all()[$locale][$domain][$key] ?? null;
    }

    public function all(): array
    {
        if ($this->map !== null) {
            return $this->map;
        }

        try {
            /** @var array<string, array<string, array<string, string>>> $map */
            $map = $this->cache->get(self::CACHE_KEY, function (ItemInterface $item): array {
                // Writes outside this service (SQL, restores) skip invalidate(): the TTL bounds staleness.
                $item->expiresAfter($this->cacheTtl);

                return $this->sanitizeMap($this->repository->loadMap());
            });
        } catch (Throwable $exception) {
            $this->logger?->warning('Translation overrides unavailable: {message}', ['message' => $exception->getMessage()]);
            $map = [];
        }

        // @igor-ignore - Per-request memo; cleared by reset() (kernel.reset) and invalidate().
        return $this->map = $map;
    }

    /**
     * Saves (non-blank $text) or removes (blank / null) one override. Markup is sanitized first.
     */
    public function set(string $locale, string $domain, string $key, ?string $text, bool $flush = true, ?string $updatedBy = null): void
    {
        $text = $this->clean($text);
        $row  = $this->repository->findOne($locale, $domain, $key);

        if ($text === '') {
            if ($row instanceof TranslationOverride) {
                // @igor-ignore - Doctrine unit-of-work call, not service state.
                $this->repository->delete($row);
            }
        } elseif (!$row instanceof TranslationOverride) {
            // @igor-ignore - Doctrine unit-of-work call, not service state.
            $this->repository->persist((new TranslationOverride($locale, $domain, $key))->setValue($text, $updatedBy));
        } elseif ($row->getValue() !== $text) {
            $row->setValue($text, $updatedBy);
        }

        if ($flush) {
            $this->flush();
        }
    }

    /**
     * Trims and sanitizes a submitted text exactly as {@see set()} stores it.
     */
    public function clean(?string $text): string
    {
        $text = $text === null ? '' : trim($text);
        if (str_contains($text, '<')) {
            return trim($this->sanitizer->sanitize($text));
        }

        return $text;
    }

    public function flush(): void
    {
        $this->repository->flush();
        $this->invalidate();
    }

    public function invalidate(): void
    {
        $this->map = null;
        try {
            $this->cache->delete(self::CACHE_KEY);
        } catch (Throwable $exception) {
            $this->logger?->warning('Translation override cache not cleared: {message}', ['message' => $exception->getMessage()]);
        }
    }

    public function reset(): void
    {
        $this->map = null;
    }

    /**
     * @param array<string, array<string, array<string, string>>> $map
     *
     * @return array<string, array<string, array<string, string>>>
     */
    private function sanitizeMap(array $map): array
    {
        foreach ($map as $locale => $domains) {
            foreach ($domains as $domain => $texts) {
                foreach ($texts as $key => $text) {
                    if (str_contains($text, '<')) {
                        $map[$locale][$domain][$key] = $this->sanitizer->sanitize($text);
                    }
                }
            }
        }

        return $map;
    }
}
