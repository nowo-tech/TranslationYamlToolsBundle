<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\TextOverride;

/**
 * Read side of operator text overrides, consumed by {@see OverridingTranslator}.
 */
interface TranslationOverrideProviderInterface
{
    /**
     * Override text for an exact (locale, domain, key), or null when the catalogue text applies.
     */
    public function get(string $locale, string $domain, string $key): ?string;

    /**
     * @return array<string, array<string, array<string, string>>> locale => domain => key => text
     */
    public function all(): array;
}
