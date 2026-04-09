<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Tests\Unit\DependencyInjection;

use Nowo\TranslationYamlToolsBundle\DependencyInjection\NowoTranslationYamlToolsExtension;
use Nowo\TranslationYamlToolsBundle\MachineTranslation\MachineTranslatorInterface;
use Nowo\TranslationYamlToolsBundle\MachineTranslation\RoutingMachineTranslator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

#[CoversClass(NowoTranslationYamlToolsExtension::class)]
final class NowoTranslationYamlToolsExtensionTest extends TestCase
{
    public function testMachineTranslatorAliasPointsToRouter(): void
    {
        $container = new ContainerBuilder();
        (new NowoTranslationYamlToolsExtension())->load([[]], $container);

        self::assertTrue($container->hasAlias(MachineTranslatorInterface::class));
        self::assertSame(
            RoutingMachineTranslator::class,
            (string) $container->getAlias(MachineTranslatorInterface::class),
        );
    }

    public function testMachineTranslatorPerLocaleParameter(): void
    {
        $container = new ContainerBuilder();
        (new NowoTranslationYamlToolsExtension())->load([[]], $container);
        self::assertFalse($container->getParameter('nowo_translation_yaml_tools.machine_translator_per_locale'));

        $container = new ContainerBuilder();
        (new NowoTranslationYamlToolsExtension())->load([[
            'machine_translator_by_locale' => ['pt_BR' => 'deepl'],
        ]], $container);
        self::assertTrue($container->getParameter('nowo_translation_yaml_tools.machine_translator_per_locale'));
        /** @var array<string, string> $map */
        $map = $container->getParameter('nowo_translation_yaml_tools.machine_translator_by_locale');
        self::assertSame(['pt_br' => 'deepl'], $map);
    }

    public function testMachineTranslationLocaleMapParameterIsCanonicalized(): void
    {
        $container = new ContainerBuilder();
        (new NowoTranslationYamlToolsExtension())->load([[
            'machine_translation_locale_map' => [
                'pt_BR' => 'pt-br',
                'PT-br' => 'should-not-win',
            ],
        ]], $container);

        /** @var array<string, string> $map */
        $map = $container->getParameter('nowo_translation_yaml_tools.machine_translation_locale_map');
        self::assertSame(['pt_br' => 'should-not-win'], $map);
    }

    public function testGetAlias(): void
    {
        $extension = new NowoTranslationYamlToolsExtension();
        self::assertSame('nowo_translation_yaml_tools', $extension->getAlias());
    }
}
