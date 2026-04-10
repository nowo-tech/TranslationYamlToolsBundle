<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\MissingTranslationLog;

/**
 * Records translation lookups where the message is not defined for the requested locale catalogue.
 */
interface MissingTranslationRecorderInterface
{
    /**
     * @param non-empty-string $id Message id / key
     * @param non-empty-string $domain Translation domain (e.g. messages)
     * @param non-empty-string $locale Requested locale
     * @param non-empty-string|null $callSite Absolute file path and line (e.g. /path/Controller.php:42), or null if unknown/disabled
     */
    public function record(string $id, string $domain, string $locale, ?string $callSite = null): void;
}
