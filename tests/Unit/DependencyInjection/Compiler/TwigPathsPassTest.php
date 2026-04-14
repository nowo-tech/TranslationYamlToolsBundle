<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Tests\Unit\DependencyInjection\Compiler;

use Nowo\TranslationYamlToolsBundle\DependencyInjection\Compiler\TwigPathsPass;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

use function dirname;

#[CoversClass(TwigPathsPass::class)]
final class TwigPathsPassTest extends TestCase
{
    public function testProcessAddsOnlyVendorPathWhenOverrideDirectoryMissing(): void
    {
        $tmp = sys_get_temp_dir() . '/tyt_twig_pass_' . bin2hex(random_bytes(4));
        self::assertTrue(mkdir($tmp, 0777, true));

        try {
            $container = $this->createContainerWithLoader($tmp, webUiEnabled: true);
            (new TwigPathsPass())->process($container);

            $loaderDef = $container->getDefinition('twig.loader.native_filesystem');
            $calls     = $loaderDef->getMethodCalls();

            self::assertSame(
                [['addPath', self::expectedVendorAddPathArgs()]],
                $calls,
            );
        } finally {
            self::removeDir($tmp);
        }
    }

    public function testProcessPrependsOverrideThenAddsVendorPathWhenOverrideDirectoryExists(): void
    {
        $tmp          = sys_get_temp_dir() . '/tyt_twig_pass_' . bin2hex(random_bytes(4));
        $overridePath = $tmp . '/templates/bundles/NowoTranslationYamlToolsBundle';
        self::assertTrue(mkdir($overridePath, 0777, true));

        try {
            $container = $this->createContainerWithLoader($tmp, webUiEnabled: true);
            (new TwigPathsPass())->process($container);

            $loaderDef = $container->getDefinition('twig.loader.native_filesystem');
            $calls     = $loaderDef->getMethodCalls();

            self::assertSame(
                [
                    ['prependPath', [$overridePath, 'NowoTranslationYamlToolsBundle']],
                    ['addPath', self::expectedVendorAddPathArgs()],
                ],
                $calls,
            );
        } finally {
            self::removeDir($tmp);
        }
    }

    public function testProcessDoesNotAddMethodCallsWhenWebUiDisabled(): void
    {
        $tmp = sys_get_temp_dir() . '/tyt_twig_pass_' . bin2hex(random_bytes(4));
        self::assertTrue(mkdir($tmp, 0777, true));

        try {
            $container = $this->createContainerWithLoader($tmp, webUiEnabled: false);
            (new TwigPathsPass())->process($container);

            self::assertSame([], $container->getDefinition('twig.loader.native_filesystem')->getMethodCalls());
        } finally {
            self::removeDir($tmp);
        }
    }

    public function testProcessUsesTwigLoaderNativeWhenAliasExists(): void
    {
        $tmp = sys_get_temp_dir() . '/tyt_twig_pass_' . bin2hex(random_bytes(4));
        self::assertTrue(mkdir($tmp, 0777, true));

        try {
            $container = new ContainerBuilder();
            $container->setParameter('kernel.project_dir', $tmp);
            $container->setParameter('nowo_translation_yaml_tools.missing_translation_log.web_ui.enabled', true);
            $loaderDef = new Definition();
            $container->setDefinition('twig.loader.native_filesystem', $loaderDef);
            $container->setAlias('twig.loader.native', 'twig.loader.native_filesystem');

            (new TwigPathsPass())->process($container);

            $addPathCalls = array_filter(
                $loaderDef->getMethodCalls(),
                static fn (array $c): bool => $c[0] === 'addPath' && ($c[1][1] ?? '') === 'NowoTranslationYamlToolsBundle',
            );
            self::assertCount(1, $addPathCalls);
        } finally {
            self::removeDir($tmp);
        }
    }

    public function testProcessDoesNothingWhenTwigLoaderNotDefined(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', sys_get_temp_dir());
        $container->setParameter('nowo_translation_yaml_tools.missing_translation_log.web_ui.enabled', true);

        (new TwigPathsPass())->process($container);

        self::assertFalse($container->hasDefinition('twig.loader.native_filesystem'));
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function expectedVendorAddPathArgs(): array
    {
        $bundleRoot = dirname(__DIR__, 4);
        $viewsPath  = $bundleRoot . '/src/Resources/views';

        return [$viewsPath, 'NowoTranslationYamlToolsBundle'];
    }

    private function createContainerWithLoader(string $projectDir, bool $webUiEnabled): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', $projectDir);
        $container->setParameter('nowo_translation_yaml_tools.missing_translation_log.web_ui.enabled', $webUiEnabled);
        $container->setDefinition('twig.loader.native_filesystem', new Definition());

        return $container;
    }

    private static function removeDir(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = scandir($path);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $full = $path . '/' . $item;
            is_dir($full) ? self::removeDir($full) : @unlink($full);
        }

        @rmdir($path);
    }
}
