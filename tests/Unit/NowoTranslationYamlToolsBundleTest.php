<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Tests\Unit;

use Nowo\TranslationYamlToolsBundle\DependencyInjection\Compiler\TwigPathsPass;
use Nowo\TranslationYamlToolsBundle\DependencyInjection\NowoTranslationYamlToolsExtension;
use Nowo\TranslationYamlToolsBundle\NowoTranslationYamlToolsBundle;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

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

    public function testBuildRegistersTwigPathsCompilerPass(): void
    {
        $bundle    = new NowoTranslationYamlToolsBundle();
        $container = new ContainerBuilder();
        $bundle->build($container);

        $config = $container->getCompilerPassConfig();
        $lists  = array_merge(
            $config->getBeforeOptimizationPasses(),
            $config->getOptimizationPasses(),
            $config->getBeforeRemovingPasses(),
            $config->getAfterRemovingPasses(),
            $config->getRemovingPasses(),
        );
        $found = false;
        foreach ($lists as $pass) {
            if ($pass instanceof TwigPathsPass) {
                $found = true;
                break;
            }
        }
        self::assertTrue($found, 'TwigPathsPass should be registered as a compiler pass');
    }
}
