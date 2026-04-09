<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\MachineTranslation;

/**
 * Pluggable machine translation backend (Google, DeepL, LibreTranslate; more providers can be added). The default
 * `MachineTranslatorInterface` service routes using `machine_translator`, `machine_translator_by_locale`, and each
 * backend applies `machine_translation_locale_map`.
 */
interface MachineTranslatorInterface
{
    /**
     * Translates plain text from a source locale to a target locale (BCP-47 style, e.g. en, es, pt_BR).
     */
    public function translate(string $text, string $sourceLocale, string $targetLocale): string;
}
