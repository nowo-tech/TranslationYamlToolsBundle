<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\TextOverride\Html;

/**
 * Fallback sanitizer used when symfony/html-sanitizer is not installed: removes every tag
 * (text is kept) and escapes any remaining "<" that could still open a tag.
 */
final class StripTagsTranslationOverrideHtmlSanitizer implements TranslationOverrideHtmlSanitizerInterface
{
    private const MAX_PASSES = 5;

    public function sanitize(string $html): string
    {
        $text = $html;
        for ($i = 0; $i < self::MAX_PASSES; ++$i) {
            $stripped = strip_tags($text);
            if ($stripped === $text) {
                break;
            }
            $text = $stripped;
        }

        return (string) preg_replace('#<(?=[a-zA-Z!/?])#', '&lt;', $text);
    }
}
