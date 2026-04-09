<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\MachineTranslation;

use InvalidArgumentException;

use function sprintf;

/**
 * Delegates {@see MachineTranslatorInterface::translate()} to Google, DeepL, or LibreTranslate based on
 * `machine_translator_by_locale` (target locale first, then source) and the default `machine_translator`.
 */
final class RoutingMachineTranslator implements MachineTranslatorInterface
{
    /**
     * @param array<string, MachineTranslatorInterface> $translators keys: google, deepl, libretranslate
     * @param array<string, string> $byCanonicalLocale canonical Symfony locale => backend name
     */
    public function __construct(
        private readonly array $translators,
        private readonly string $defaultBackend,
        private readonly array $byCanonicalLocale,
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function translate(string $text, string $sourceLocale, string $targetLocale): string
    {
        $backend = $this->resolveBackend($sourceLocale, $targetLocale);

        return $this->delegate($backend)->translate($text, $sourceLocale, $targetLocale);
    }

    private function resolveBackend(string $sourceLocale, string $targetLocale): string
    {
        $targetKey = MachineTranslationLocaleMapper::canonicalLocaleKey($targetLocale);
        $sourceKey = MachineTranslationLocaleMapper::canonicalLocaleKey($sourceLocale);
        if ($targetKey !== '' && isset($this->byCanonicalLocale[$targetKey])) {
            return $this->byCanonicalLocale[$targetKey];
        }
        if ($sourceKey !== '' && isset($this->byCanonicalLocale[$sourceKey])) {
            return $this->byCanonicalLocale[$sourceKey];
        }

        return $this->defaultBackend;
    }

    private function delegate(string $backend): MachineTranslatorInterface
    {
        if (!isset($this->translators[$backend])) {
            throw new InvalidArgumentException(sprintf('Unknown machine translator backend "%s".', $backend));
        }

        return $this->translators[$backend];
    }
}
