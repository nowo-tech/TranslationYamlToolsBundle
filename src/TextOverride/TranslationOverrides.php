<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\TextOverride;

use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\TranslationYamlToolsBundle\Concurrency\TranslationYamlToolsWriteUnit;
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
 * and memoised per unit of work (dropped at the request, Messenger and console boundaries by
 * {@see EventSubscriber\TranslationOverridesClearSubscriber}, and by {@code kernel.reset}); every write through this service drops the cache entry. Any storage
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

        // @igor-ignore - Per-unit-of-work memo; cleared by TranslationOverridesClearSubscriber at the request / Messenger / console boundaries, reset() (kernel.reset) and invalidate().
        return $this->map = $map;
    }

    /**
     * Saves (non-blank $text) or removes (blank / null) one override. Markup is sanitized first.
     *
     * With `$flush` true (the default) the change is one write unit ({@see self::write()}); with false it is only
     * staged on the bundle's manager: write it with {@see self::flush()}.
     *
     * @throws TransientStorageFailure when the write met a lock conflict (`$flush` true)
     */
    public function set(string $locale, string $domain, string $key, ?string $text, bool $flush = true, ?string $updatedBy = null): void
    {
        if (!$flush) {
            $this->stage($locale, $domain, $key, $text, $updatedBy);

            return;
        }

        $this->write(function () use ($locale, $domain, $key, $text, $updatedBy): void {
            $this->stage($locale, $domain, $key, $text, $updatedBy);
        });
    }

    /**
     * Runs `$stage` (calls of {@see self::set()} with `$flush` false) and the flush as one write unit (1.19,
     * REQ-RUNTIME-003 rules 1 and 4): one transaction, then the cached map is dropped.
     *
     * The first save of a message by two requests at once is the expected "only one" race of the unique key
     * (locale, domain, key): the loser's insert fails with a unique violation, and the unit runs `$stage` once more,
     * which now finds the winner's row and updates it (the last save wins, as for two saves of an existing row). Any
     * other failure, and a second unique violation, is rethrown; a unique violation is never a lock conflict.
     *
     * @param callable(): void $stage reads the rows again on every run (never reuse an entity loaded outside)
     *
     * @throws TransientStorageFailure when the write met a lock conflict (deadlock, lock wait timeout, serialization)
     */
    public function write(callable $stage): void
    {
        try {
            $this->repository->transactional($stage);
        } catch (Throwable $failure) {
            if (!TranslationYamlToolsWriteUnit::isUniqueViolation($failure)) {
                throw $failure;
            }
            $this->repository->transactional($stage);
        }
        $this->invalidate();
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

    /**
     * Writes the staged overrides as one write unit (1.19: {@see TranslationOverrideRepository::flush()}) and drops the
     * cached map.
     *
     * @throws TransientStorageFailure when the write met a lock conflict
     */
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
     * Stages one change on the bundle's manager (no flush): the row is read again from the manager every time.
     */
    private function stage(string $locale, string $domain, string $key, ?string $text, ?string $updatedBy): void
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
