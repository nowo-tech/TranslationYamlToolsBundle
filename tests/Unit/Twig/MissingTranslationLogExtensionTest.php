<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Tests\Unit\Twig;

use Nowo\TranslationYamlToolsBundle\Twig\MissingTranslationLogExtension;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MissingTranslationLogExtension::class)]
final class MissingTranslationLogExtensionTest extends TestCase
{
    public function testGetGlobalsExposesLayoutTemplate(): void
    {
        $ext = new MissingTranslationLogExtension('@App/layout.html.twig');
        $g   = $ext->getGlobals();
        self::assertSame('@App/layout.html.twig', $g[MissingTranslationLogExtension::GLOBAL_LAYOUT_TEMPLATE]);
        self::assertSame('bootstrap5', $g[MissingTranslationLogExtension::GLOBAL_CSS_FRAMEWORK]);
    }

    public function testGetGlobalsExposesCssFramework(): void
    {
        $ext = new MissingTranslationLogExtension('@App/layout.html.twig', 'tailwind');
        $g   = $ext->getGlobals();
        self::assertSame('tailwind', $g[MissingTranslationLogExtension::GLOBAL_CSS_FRAMEWORK]);
    }
}
