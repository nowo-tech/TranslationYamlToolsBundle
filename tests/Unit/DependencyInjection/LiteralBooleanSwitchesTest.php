<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Tests\Unit\DependencyInjection;

use Nowo\TranslationYamlToolsBundle\DependencyInjection\NowoTranslationYamlToolsExtension;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Compiler\MergeExtensionConfigurationPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * REQ-SEC-004 L1: the switches that open a Web UI or skip its access check must be literal booleans. An
 * "%env(bool:…)%" placeholder used to be (bool)-cast as a placeholder string — always true, whatever the env value
 * (fail-open). It is now refused at container build time.
 */
#[CoversClass(NowoTranslationYamlToolsExtension::class)]
final class LiteralBooleanSwitchesTest extends TestCase
{
    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function placeholderConfigs(): iterable
    {
        $env = '%env(bool:TYT_UI_PUBLIC)%';

        yield 'missing log security.allow_unauthenticated' => [
            ['missing_translation_log' => ['enabled' => true, 'web_ui' => ['enabled' => true, 'security' => ['allow_unauthenticated' => $env]]]],
            'missing_translation_log.web_ui.security.allow_unauthenticated',
        ];
        yield 'missing log deprecated top-level allow_unauthenticated' => [
            ['missing_translation_log' => ['enabled' => true, 'web_ui' => ['enabled' => true, 'allow_unauthenticated' => $env]]],
            'missing_translation_log.web_ui.security.allow_unauthenticated',
        ];
        yield 'missing log web_ui.enabled' => [
            ['missing_translation_log' => ['enabled' => true, 'web_ui' => ['enabled' => $env]]],
            'missing_translation_log.web_ui.enabled',
        ];
        yield 'missing log enabled' => [
            ['missing_translation_log' => ['enabled' => $env]],
            'missing_translation_log.enabled',
        ];
        yield 'overrides security.allow_unauthenticated' => [
            ['overrides' => ['enabled' => true, 'web_ui' => ['enabled' => true, 'security' => ['allow_unauthenticated' => $env]]]],
            'overrides.web_ui.security.allow_unauthenticated',
        ];
        yield 'overrides web_ui.enabled' => [
            ['overrides' => ['enabled' => true, 'web_ui' => ['enabled' => $env]]],
            'overrides.web_ui.enabled',
        ];
        yield 'overrides enabled' => [
            ['overrides' => ['enabled' => $env]],
            'overrides.enabled',
        ];
    }

    /**
     * @param array<string, mixed> $config
     */
    #[DataProvider('placeholderConfigs')]
    public function testEnvPlaceholderIsRefusedWhenTheContainerIsBuilt(array $config, string $path): void
    {
        // Whatever TYT_UI_PUBLIC holds at runtime, the switch is refused instead of cast to true.
        $container = new ContainerBuilder();
        $container->setParameter('kernel.debug', false);
        $container->registerExtension(new NowoTranslationYamlToolsExtension());
        $container->loadFromExtension('nowo_translation_yaml_tools', $config);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('nowo_translation_yaml_tools.' . $path . ' must be a literal boolean');
        (new MergeExtensionConfigurationPass())->process($container);
    }

    public function testLiteralBooleansStillLoad(): void
    {
        $container = new ContainerBuilder();
        (new NowoTranslationYamlToolsExtension())->load([[
            'missing_translation_log' => ['enabled' => true, 'web_ui' => ['enabled' => true, 'allow_unauthenticated' => false]],
            'overrides'               => ['enabled' => true, 'web_ui' => ['enabled' => true, 'security' => ['allow_unauthenticated' => false]]],
        ]], $container);

        self::assertFalse($container->getParameter('nowo_translation_yaml_tools.missing_translation_log.web_ui.security.allow_unauthenticated'));
        self::assertFalse($container->getParameter('nowo_translation_yaml_tools.overrides.web_ui.security.allow_unauthenticated'));
        self::assertTrue($container->getParameter('nowo_translation_yaml_tools.overrides.web_ui.enabled'));
    }
}
