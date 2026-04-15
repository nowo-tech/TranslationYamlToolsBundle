<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Translation;

/**
 * Structured context for one missing-key hit: optional backtrace (call site) and optional HTTP request fields.
 */
final class MissingTranslationRecordContext
{
    public function __construct(
        public readonly ?string $callSite,
        public readonly ?string $requestRoute,
        public readonly ?string $requestMethod,
        public readonly ?string $requestPath,
    ) {
    }
}
