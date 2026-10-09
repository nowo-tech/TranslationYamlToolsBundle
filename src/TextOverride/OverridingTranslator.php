<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\TextOverride;

use Closure;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\CacheWarmer\WarmableInterface;
use Symfony\Component\Translation\Formatter\MessageFormatterInterface;
use Symfony\Component\Translation\MessageCatalogueInterface;
use Symfony\Component\Translation\TranslatorBagInterface;
use Symfony\Contracts\Translation\LocaleAwareInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

use function sprintf;

/**
 * Serves operator text overrides before the catalogues (decorates the {@code translator} service).
 *
 * Twig |trans, forms, mails and PHP trans() all see the edited text. Only an exact
 * (locale, domain, key) match is replaced; parameters, plurals and ICU messages are formatted with
 * the framework message formatter, like catalogue messages. Catalogue access, locale handling and
 * cache warm-up are delegated unchanged, so {@see getCatalogue()} keeps returning the shipped text.
 *
 * The provider is resolved lazily (service closure) so building the translator never touches
 * Doctrine or the cache pool.
 *
 * @method mixed getFormats()
 */
final class OverridingTranslator implements TranslatorInterface, TranslatorBagInterface, LocaleAwareInterface, WarmableInterface
{
    /** @var LocaleAwareInterface&TranslatorBagInterface&TranslatorInterface */
    private TranslatorInterface $inner;

    /** @var Closure(): TranslationOverrideProviderInterface */
    private readonly Closure $overrides;

    /**
     * @param Closure(): TranslationOverrideProviderInterface|TranslationOverrideProviderInterface $overrides
     */
    public function __construct(
        TranslatorInterface $inner,
        Closure|TranslationOverrideProviderInterface $overrides,
        private readonly MessageFormatterInterface $formatter,
    ) {
        if (!$inner instanceof TranslatorBagInterface || !$inner instanceof LocaleAwareInterface) {
            throw new InvalidArgumentException(sprintf('The decorated translator must implement %s and %s.', TranslatorBagInterface::class, LocaleAwareInterface::class));
        }

        $this->inner     = $inner;
        $this->overrides = $overrides instanceof Closure ? $overrides : static fn (): TranslationOverrideProviderInterface => $overrides;
    }

    /**
     * @param array<array-key, mixed> $parameters
     */
    public function trans(?string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
    {
        $id = (string) $id;
        if ($id !== '') {
            $locale ??= $this->inner->getLocale();
            $text = ($this->overrides)()->get($locale, $domain ?? 'messages', $id);
            if ($text !== null) {
                return $this->formatter->format($text, $locale, $parameters);
            }
        }

        return $this->inner->trans($id, $parameters, $domain, $locale);
    }

    public function getCatalogue(?string $locale = null): MessageCatalogueInterface
    {
        return $this->inner->getCatalogue($locale);
    }

    /**
     * @return array<string, MessageCatalogueInterface>
     */
    public function getCatalogues(): array
    {
        return $this->inner->getCatalogues();
    }

    public function setLocale(string $locale): void
    {
        $this->inner->setLocale($locale);
    }

    public function getLocale(): string
    {
        return $this->inner->getLocale();
    }

    /**
     * @return string[]
     */
    public function warmUp(string $cacheDir, ?string $buildDir = null): array
    {
        return $this->inner instanceof WarmableInterface ? $this->inner->warmUp($cacheDir, $buildDir) : [];
    }

    /**
     * Symfony's Translator / DataCollectorTranslator expose this; some bundles call it.
     *
     * @return list<string>
     */
    public function getFallbackLocales(): array
    {
        if (!method_exists($this->inner, 'getFallbackLocales')) {
            return [];
        }

        /** @var list<string> $locales */
        $locales = $this->inner->getFallbackLocales();

        return $locales;
    }

    /**
     * Forwards other public methods of the decorated translator (e.g. getFormats()).
     *
     * @param mixed[] $args
     */
    public function __call(string $method, array $args): mixed
    {
        return $this->inner->{$method}(...$args);
    }
}
