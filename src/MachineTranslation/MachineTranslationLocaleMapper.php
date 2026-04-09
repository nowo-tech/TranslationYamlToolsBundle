<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\MachineTranslation;

/**
 * Maps Symfony translation locale identifiers to provider-specific language codes configured in the bundle.
 *
 * Lookup uses a canonical key: lowercase, underscores for separators (e.g. `pt_BR`, `pt-br`, `PT_BR` → `pt_br`).
 */
final class MachineTranslationLocaleMapper
{
    /**
     * @param array<string, string> $canonicalKeyToApiCode canonical locale key (see {@see canonicalLocaleKey()}) => code sent to the API as-is
     */
    public function __construct(
        private readonly array $canonicalKeyToApiCode,
    ) {
    }

    /**
     * Canonical key for matching YAML config keys and CLI locales regardless of {@code _} vs {@code -} or case.
     */
    public static function canonicalLocaleKey(string $locale): string
    {
        $locale = trim($locale);
        if ($locale === '') {
            return '';
        }

        return strtolower(str_replace('-', '_', $locale));
    }

    /**
     * Returns the configured API code, or null to let the translator apply its default normalization.
     */
    public function map(string $symfonyLocale): ?string
    {
        $key = self::canonicalLocaleKey($symfonyLocale);
        if ($key === '') {
            return null;
        }

        return $this->canonicalKeyToApiCode[$key] ?? null;
    }
}
