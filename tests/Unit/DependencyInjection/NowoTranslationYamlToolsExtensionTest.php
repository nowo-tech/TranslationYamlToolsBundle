<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Tests\Unit\DependencyInjection;

use Nowo\TranslationYamlToolsBundle\Controller\MissingTranslationLogUiController;
use Nowo\TranslationYamlToolsBundle\DependencyInjection\NowoTranslationYamlToolsExtension;
use Nowo\TranslationYamlToolsBundle\MachineTranslation\MachineTranslatorInterface;
use Nowo\TranslationYamlToolsBundle\MachineTranslation\RoutingMachineTranslator;
use Nowo\TranslationYamlToolsBundle\MissingTranslationLog\MissingTranslationBufferDoctrinePersistListener;
use Nowo\TranslationYamlToolsBundle\MissingTranslationLog\PersistMissingTranslationBufferMessageHandler;
use Nowo\TranslationYamlToolsBundle\Translation\RecordingTranslatorDecorator;
use Nowo\TranslationYamlToolsBundle\Twig\MissingTranslationLogExtension;
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

    public function testMissingTranslationLogParameterDefaultsToDisabled(): void
    {
        $container = new ContainerBuilder();
        (new NowoTranslationYamlToolsExtension())->load([[]], $container);

        self::assertFalse($container->getParameter('nowo_translation_yaml_tools.missing_translation_log.enabled'));
        self::assertSame('nowo_translation_', $container->getParameter('nowo_translation_yaml_tools.missing_translation_log.table_prefix'));
        self::assertTrue($container->getParameter('nowo_translation_yaml_tools.missing_translation_log.record_call_site'));
        self::assertFalse($container->getParameter('nowo_translation_yaml_tools.missing_translation_log.async_persist'));
        self::assertSame('messenger', $container->getParameter('nowo_translation_yaml_tools.missing_translation_log.async_persist_strategy'));
        self::assertFalse($container->getParameter('nowo_translation_yaml_tools.missing_translation_log.web_ui.enabled'));
        self::assertSame(
            '@NowoTranslationYamlToolsBundle/missing_translation_log/layout.html.twig',
            $container->getParameter('nowo_translation_yaml_tools.missing_translation_log.web_ui.layout_template'),
        );
        self::assertFalse($container->hasDefinition(RecordingTranslatorDecorator::class));
        self::assertFalse($container->hasDefinition(MissingTranslationLogUiController::class));
    }

    public function testMissingTranslationLogEnabledRegistersDecoratorDefinition(): void
    {
        $container = new ContainerBuilder();
        (new NowoTranslationYamlToolsExtension())->load([[
            'missing_translation_log' => ['enabled' => true],
        ]], $container);

        self::assertTrue($container->getParameter('nowo_translation_yaml_tools.missing_translation_log.enabled'));
        self::assertTrue($container->hasDefinition(RecordingTranslatorDecorator::class));
        self::assertFalse($container->hasDefinition(MissingTranslationLogUiController::class));
    }

    public function testMissingTranslationLogWebUiRegistersController(): void
    {
        $container = new ContainerBuilder();
        (new NowoTranslationYamlToolsExtension())->load([[
            'missing_translation_log' => [
                'enabled' => true,
                'web_ui'  => ['enabled' => true],
            ],
        ]], $container);

        self::assertTrue($container->getParameter('nowo_translation_yaml_tools.missing_translation_log.web_ui.enabled'));
        self::assertTrue($container->hasDefinition(MissingTranslationLogUiController::class));
        self::assertTrue($container->hasDefinition(MissingTranslationLogExtension::class));
    }

    public function testMissingTranslationLogAsyncPersistRegistersMessengerHandlerWhenMessengerAvailable(): void
    {
        if (!interface_exists(\Symfony\Component\Messenger\MessageBusInterface::class)) {
            self::markTestSkipped('symfony/messenger is not installed');
        }

        $container = new ContainerBuilder();
        (new NowoTranslationYamlToolsExtension())->load([[
            'missing_translation_log' => [
                'enabled'       => true,
                'async_persist' => true,
            ],
        ]], $container);

        self::assertTrue($container->getParameter('nowo_translation_yaml_tools.missing_translation_log.async_persist'));
        self::assertTrue($container->hasDefinition(PersistMissingTranslationBufferMessageHandler::class));
    }

    public function testMissingTranslationLogAsyncPersistEventDispatcherRegistersBuiltinListener(): void
    {
        $container = new ContainerBuilder();
        (new NowoTranslationYamlToolsExtension())->load([[
            'missing_translation_log' => [
                'enabled'                => true,
                'async_persist'          => true,
                'async_persist_strategy' => 'event_dispatcher',
            ],
        ]], $container);

        self::assertSame('event_dispatcher', $container->getParameter('nowo_translation_yaml_tools.missing_translation_log.async_persist_strategy'));
        self::assertTrue($container->hasDefinition(MissingTranslationBufferDoctrinePersistListener::class));
    }
}
