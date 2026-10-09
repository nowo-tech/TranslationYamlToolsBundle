<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\TextOverride\Html;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;

/**
 * Default sanitizer when symfony/html-sanitizer is installed: an inline allowlist (links,
 * emphasis, line breaks, small/code/abbr/span) with http(s)/mailto/tel and relative links only.
 * Scripts, styles, event handlers and javascript: URLs are removed.
 *
 * Pass your own {@see HtmlSanitizerInterface} (e.g. a framework.html_sanitizer service) to change
 * the allowlist.
 */
final class SymfonyTranslationOverrideHtmlSanitizer implements TranslationOverrideHtmlSanitizerInterface
{
    private readonly HtmlSanitizerInterface $sanitizer;

    public function __construct(?HtmlSanitizerInterface $sanitizer = null)
    {
        $this->sanitizer = $sanitizer ?? new HtmlSanitizer(self::defaultConfig());
    }

    public function sanitize(string $html): string
    {
        return $this->sanitizer->sanitize($html);
    }

    public static function defaultConfig(): HtmlSanitizerConfig
    {
        return (new HtmlSanitizerConfig())
            ->allowElement('a', ['href', 'title', 'target'])
            ->forceAttribute('a', 'rel', 'noopener noreferrer')
            ->allowElement('strong')
            ->allowElement('b')
            ->allowElement('em')
            ->allowElement('i')
            ->allowElement('u')
            ->allowElement('s')
            ->allowElement('small')
            ->allowElement('code')
            ->allowElement('abbr', ['title'])
            ->allowElement('span', ['class'])
            ->allowElement('br')
            ->allowLinkSchemes(['http', 'https', 'mailto', 'tel'])
            ->allowRelativeLinks();
    }
}
