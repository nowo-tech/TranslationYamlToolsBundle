<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\TextOverride\Html;

/**
 * Cleans markup in operator text overrides.
 *
 * Called for values that contain "<", both when an override is saved and when the override map is
 * loaded (rows written outside the bundle — SQL, backups, imports — are cleaned too). Some messages
 * are printed with {@code |raw}, so implementations must remove scripts, event handlers and
 * script URLs. Implementations should be idempotent.
 *
 * Configure your own service with {@code nowo_translation_yaml_tools.overrides.html_sanitizer}.
 */
interface TranslationOverrideHtmlSanitizerInterface
{
    public function sanitize(string $html): string;
}
