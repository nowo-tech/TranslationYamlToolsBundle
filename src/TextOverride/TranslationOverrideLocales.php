<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\TextOverride;

use Nowo\TranslationYamlToolsBundle\Service\TranslationDefaultLocaleResolver;

use function in_array;
use function is_string;

/**
 * Locales offered by the override editor: {@code overrides.locales}, else
 * {@code framework.enabled_locales}, else the default locale alone. The default locale
 * ({@code default_locale} / translator default) always comes first.
 */
final class TranslationOverrideLocales
{
    /** @var list<string> */
    private readonly array $locales;

    private readonly string $defaultLocale;

    /**
     * @param list<string> $configured overrides.locales
     * @param mixed[] $enabled %kernel.enabled_locales%
     */
    public function __construct(
        TranslationDefaultLocaleResolver $defaultLocaleResolver,
        array $configured = [],
        array $enabled = [],
        ?string $bundleDefaultLocale = null,
    ) {
        $this->defaultLocale = $defaultLocaleResolver->resolve($bundleDefaultLocale);
        $source              = $configured !== [] ? $configured : $enabled;

        $locales = [$this->defaultLocale];
        foreach ($source as $locale) {
            if (is_string($locale) && $locale !== '' && !in_array($locale, $locales, true)) {
                $locales[] = $locale;
            }
        }
        $this->locales = $locales;
    }

    /**
     * @return list<string>
     */
    public function all(): array
    {
        return $this->locales;
    }

    public function default(): string
    {
        return $this->defaultLocale;
    }
}
