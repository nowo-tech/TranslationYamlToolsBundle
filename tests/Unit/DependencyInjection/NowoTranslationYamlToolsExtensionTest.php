<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Tests\Unit\DependencyInjection;

use Nowo\TranslationYamlToolsBundle\DependencyInjection\NowoTranslationYamlToolsExtension;
use Nowo\TranslationYamlToolsBundle\MachineTranslation\DeeplMachineTranslator;
use Nowo\TranslationYamlToolsBundle\MachineTranslation\GoogleTranslateMachineTranslator;
use Nowo\TranslationYamlToolsBundle\MachineTranslation\LibreTranslateMachineTranslator;
use Nowo\TranslationYamlToolsBundle\MachineTranslation\MachineTranslatorInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

#[CoversClass(NowoTranslationYamlToolsExtension::class)]
final class NowoTranslationYamlToolsExtensionTest extends TestCase
{
    public function testMachineTranslatorAliasPointsToGoogleByDefault(): void
    {
        $container = new ContainerBuilder();
        (new NowoTranslationYamlToolsExtension())->load([[]], $container);

        self::assertTrue($container->hasAlias(MachineTranslatorInterface::class));
        self::assertSame(
            GoogleTranslateMachineTranslator::class,
            (string) $container->getAlias(MachineTranslatorInterface::class),
        );
    }

    public function testMachineTranslatorAliasPointsToDeeplWhenConfigured(): void
    {
        $container = new ContainerBuilder();
        (new NowoTranslationYamlToolsExtension())->load([['machine_translator' => 'deepl']], $container);

        self::assertTrue($container->hasAlias(MachineTranslatorInterface::class));
        self::assertSame(
            DeeplMachineTranslator::class,
            (string) $container->getAlias(MachineTranslatorInterface::class),
        );
    }

    public function testMachineTranslatorAliasPointsToLibretranslateWhenConfigured(): void
    {
        $container = new ContainerBuilder();
        (new NowoTranslationYamlToolsExtension())->load([['machine_translator' => 'libretranslate']], $container);

        self::assertTrue($container->hasAlias(MachineTranslatorInterface::class));
        self::assertSame(
            LibreTranslateMachineTranslator::class,
            (string) $container->getAlias(MachineTranslatorInterface::class),
        );
    }

    public function testGetAlias(): void
    {
        $extension = new NowoTranslationYamlToolsExtension();
        self::assertSame('nowo_translation_yaml_tools', $extension->getAlias());
    }
}
