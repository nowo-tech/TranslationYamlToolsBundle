<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Tests\Unit\TextOverride;

use Nowo\TranslationYamlToolsBundle\TextOverride\Html\StripTagsTranslationOverrideHtmlSanitizer;
use Nowo\TranslationYamlToolsBundle\TextOverride\Html\SymfonyTranslationOverrideHtmlSanitizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;

#[CoversClass(SymfonyTranslationOverrideHtmlSanitizer::class)]
#[CoversClass(StripTagsTranslationOverrideHtmlSanitizer::class)]
final class HtmlSanitizersTest extends TestCase
{
    public function testSymfonySanitizerKeepsInlineAllowlistOnly(): void
    {
        $sanitizer = new SymfonyTranslationOverrideHtmlSanitizer();

        $clean = $sanitizer->sanitize('<strong>Hi</strong> <a href="https://example.com" target="_blank" onclick="x()">site</a> <a href="javascript:alert(1)">bad</a><script>alert(2)</script><img src=x onerror=alert(3)><br>');

        self::assertStringContainsString('<strong>Hi</strong>', $clean);
        self::assertStringContainsString('<a href="https://example.com" target="_blank" rel="noopener noreferrer">site</a>', $clean);
        self::assertStringNotContainsString('javascript:', $clean);
        self::assertStringNotContainsString('script', $clean);
        self::assertStringNotContainsString('onerror', $clean);
        self::assertStringNotContainsString('<img', $clean);
        self::assertSame($clean, $sanitizer->sanitize($clean), 'Idempotent.');
    }

    public function testSymfonySanitizerAcceptsCustomSanitizer(): void
    {
        $inner = $this->createMock(HtmlSanitizerInterface::class);
        $inner->expects(self::once())->method('sanitize')->with('<b>x</b>')->willReturn('x');

        self::assertSame('x', (new SymfonyTranslationOverrideHtmlSanitizer($inner))->sanitize('<b>x</b>'));
    }

    public function testStripTagsFallbackRemovesAllMarkup(): void
    {
        $sanitizer = new StripTagsTranslationOverrideHtmlSanitizer();

        self::assertSame('I accept the policy', $sanitizer->sanitize('I accept the <a href="/p" onclick="x()">policy</a>'));
        self::assertSame('alert(1)', $sanitizer->sanitize('<script>alert(1)</script>'));
        self::assertSame('5 < 6', $sanitizer->sanitize('5 < 6'));
        self::assertStringNotContainsString('<script', $sanitizer->sanitize('<<a>script>alert(1)<</a>/script>'));
    }
}
