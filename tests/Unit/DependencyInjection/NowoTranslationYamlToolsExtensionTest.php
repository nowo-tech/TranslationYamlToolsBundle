<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Tests\Unit\DependencyInjection;

use Doctrine\Bundle\DoctrineBundle\DependencyInjection\DoctrineExtension;
use Nowo\TranslationYamlToolsBundle\Controller\MissingTranslationLogUiController;
use Nowo\TranslationYamlToolsBundle\DependencyInjection\NowoTranslationYamlToolsExtension;
use Nowo\TranslationYamlToolsBundle\EventSubscriber\MissingLogUiAccessSubscriber;
use Nowo\TranslationYamlToolsBundle\MachineTranslation\MachineTranslatorInterface;
use Nowo\TranslationYamlToolsBundle\MachineTranslation\RoutingMachineTranslator;
use Nowo\TranslationYamlToolsBundle\MissingTranslationLog\MissingTranslationBufferDoctrinePersistListener;
use Nowo\TranslationYamlToolsBundle\MissingTranslationLog\PersistMissingTranslationBufferMessageHandler;
use Nowo\TranslationYamlToolsBundle\Translation\RecordingTranslatorDecorator;
use Nowo\TranslationYamlToolsBundle\Twig\MissingTranslationLogExtension;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use stdClass;
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

    public function testYamlTreeLeafPrefixSuffixParameters(): void
    {
        $container = new ContainerBuilder();
        (new NowoTranslationYamlToolsExtension())->load([[]], $container);
        self::assertSame('index', $container->getParameter('nowo_translation_yaml_tools.yaml_tree_leaf_prefix_suffix'));

        $container = new ContainerBuilder();
        (new NowoTranslationYamlToolsExtension())->load([[
            'yaml_tree_leaf_prefix_suffix' => 'text',
        ]], $container);
        self::assertSame('text', $container->getParameter('nowo_translation_yaml_tools.yaml_tree_leaf_prefix_suffix'));
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
        self::assertTrue($container->getParameter('nowo_translation_yaml_tools.missing_translation_log.record_request_context'));
        self::assertFalse($container->getParameter('nowo_translation_yaml_tools.missing_translation_log.async_persist'));
        self::assertSame('messenger', $container->getParameter('nowo_translation_yaml_tools.missing_translation_log.async_persist_strategy'));
        self::assertFalse($container->getParameter('nowo_translation_yaml_tools.missing_translation_log.web_ui.enabled'));
        self::assertSame(
            '@NowoTranslationYamlToolsBundle/missing_translation_log/layout.html.twig',
            $container->getParameter('nowo_translation_yaml_tools.missing_translation_log.web_ui.layout_template'),
        );
        self::assertSame('ROLE_ADMIN', $container->getParameter('nowo_translation_yaml_tools.missing_translation_log.web_ui.required_role'));
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

    public function testMissingTranslationLogWebUiRegistersAccessSubscriberWhenSecurityAvailable(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition('security.authorization_checker', new \Symfony\Component\DependencyInjection\Definition(stdClass::class));

        (new NowoTranslationYamlToolsExtension())->load([[
            'missing_translation_log' => [
                'enabled' => true,
                'web_ui'  => ['enabled' => true, 'required_role' => 'ROLE_ADMIN'],
            ],
        ]], $container);

        self::assertTrue($container->hasDefinition(MissingLogUiAccessSubscriber::class));
        self::assertSame('ROLE_ADMIN', $container->getParameter('nowo_translation_yaml_tools.missing_translation_log.web_ui.required_role'));
    }

    public function testMissingTranslationLogWebUiSkipsAccessSubscriberWhenRequiredRoleNull(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition('security.authorization_checker', new \Symfony\Component\DependencyInjection\Definition(stdClass::class));

        (new NowoTranslationYamlToolsExtension())->load([[
            'missing_translation_log' => [
                'enabled' => true,
                'web_ui'  => ['enabled' => true, 'required_role' => null],
            ],
        ]], $container);

        self::assertNull($container->getParameter('nowo_translation_yaml_tools.missing_translation_log.web_ui.required_role'));
        self::assertFalse($container->hasDefinition(MissingLogUiAccessSubscriber::class));
    }

    public function testMissingTranslationLogWebUiEmptyRequiredRoleBecomesNull(): void
    {
        $container = new ContainerBuilder();
        (new NowoTranslationYamlToolsExtension())->load([[
            'missing_translation_log' => [
                'enabled' => true,
                'web_ui'  => ['enabled' => true, 'required_role' => ''],
            ],
        ]], $container);

        self::assertNull($container->getParameter('nowo_translation_yaml_tools.missing_translation_log.web_ui.required_role'));
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

    public function testLoadWithMissingTranslationLogFalseSkipsExtraYaml(): void
    {
        $container = new ContainerBuilder();
        (new NowoTranslationYamlToolsExtension())->load([['missing_translation_log' => false]], $container);

        self::assertFalse($container->getParameter('nowo_translation_yaml_tools.missing_translation_log.enabled'));
        self::assertFalse($container->hasDefinition(RecordingTranslatorDecorator::class));
    }

    public function testPrependAddsDoctrineMappingWhenDoctrineExtensionPresentAndMissingLogEnabled(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension(new DoctrineExtension());
        $extension = new NowoTranslationYamlToolsExtension();
        $container->registerExtension($extension);
        $container->loadFromExtension('nowo_translation_yaml_tools', [
            'missing_translation_log' => ['enabled' => true],
        ]);

        $extension->prepend($container);

        $doctrineConfigs = $container->getExtensionConfig('doctrine');
        self::assertNotEmpty($doctrineConfigs);
        $merged = array_merge_recursive(...$doctrineConfigs);
        self::assertArrayHasKey('orm', $merged);
        self::assertArrayHasKey('mappings', $merged['orm']);
        self::assertArrayHasKey('NowoTranslationYamlToolsMissingLog', $merged['orm']['mappings']);
    }

    public function testPrependDoesNothingWhenMissingLogDisabled(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension(new DoctrineExtension());
        $extension = new NowoTranslationYamlToolsExtension();
        $container->registerExtension($extension);
        $container->loadFromExtension('nowo_translation_yaml_tools', [
            'missing_translation_log' => ['enabled' => false],
        ]);

        $extension->prepend($container);

        self::assertSame([], $container->getExtensionConfig('doctrine'));
    }

    public function testPrependDoesNothingWhenDoctrineExtensionMissing(): void
    {
        $container = new ContainerBuilder();
        $extension = new NowoTranslationYamlToolsExtension();
        $container->registerExtension($extension);
        $container->loadFromExtension('nowo_translation_yaml_tools', [
            'missing_translation_log' => ['enabled' => true],
        ]);

        $extension->prepend($container);

        self::assertSame([], $container->getExtensionConfig('doctrine'));
    }

    public function testRawConfigEnablesMissingTranslationLogViaReflection(): void
    {
        $reflection = new ReflectionMethod(NowoTranslationYamlToolsExtension::class, 'rawConfigEnablesMissingTranslationLog');
        $reflection->setAccessible(true);

        $container = new ContainerBuilder();
        $ext1      = new NowoTranslationYamlToolsExtension();
        $container->registerExtension($ext1);
        $container->loadFromExtension('nowo_translation_yaml_tools', [
            'missing_translation_log' => false,
        ]);
        self::assertFalse($reflection->invoke($ext1, $container));

        $extension2 = new NowoTranslationYamlToolsExtension();
        $container2 = new ContainerBuilder();
        $container2->registerExtension($extension2);
        $container2->loadFromExtension('nowo_translation_yaml_tools', [
            'missing_translation_log' => ['enabled' => true],
        ]);
        self::assertTrue($reflection->invoke($extension2, $container2));
    }

    public function testRawConfigEnablesMissingTranslationLogSkipsNonArrayChunks(): void
    {
        $reflection = new ReflectionMethod(NowoTranslationYamlToolsExtension::class, 'rawConfigEnablesMissingTranslationLog');
        $reflection->setAccessible(true);

        $container = $this->createMock(ContainerBuilder::class);
        $container->method('getExtensionConfig')->with('nowo_translation_yaml_tools')->willReturn([
            null,
            123,
            ['missing_translation_log' => ['enabled' => true]],
        ]);

        self::assertTrue($reflection->invoke(new NowoTranslationYamlToolsExtension(), $container));
    }
}
