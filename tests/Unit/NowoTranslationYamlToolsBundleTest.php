<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Tests\Unit;

use Nowo\TranslationYamlToolsBundle\DependencyInjection\NowoTranslationYamlToolsExtension;
use Nowo\TranslationYamlToolsBundle\NowoTranslationYamlToolsBundle;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(NowoTranslationYamlToolsBundle::class)]
final class NowoTranslationYamlToolsBundleTest extends TestCase
{
    public function testGetContainerExtensionReturnsSingleton(): void
    {
        $bundle = new NowoTranslationYamlToolsBundle();
        $a      = $bundle->getContainerExtension();
        $b      = $bundle->getContainerExtension();
        self::assertInstanceOf(NowoTranslationYamlToolsExtension::class, $a);
        self::assertSame($a, $b);
    }
}
