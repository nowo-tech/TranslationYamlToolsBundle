<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Tests\Unit\DependencyInjection;

use Doctrine\Bundle\DoctrineBundle\DependencyInjection\DoctrineExtension;
use Nowo\TranslationYamlToolsBundle\DependencyInjection\NowoTranslationYamlToolsExtension;
use Nowo\TranslationYamlToolsBundle\TextOverride\Controller\TranslationOverrideController;
use Nowo\TranslationYamlToolsBundle\TextOverride\Html\SymfonyTranslationOverrideHtmlSanitizer;
use Nowo\TranslationYamlToolsBundle\TextOverride\Html\TranslationOverrideHtmlSanitizerInterface;
use Nowo\TranslationYamlToolsBundle\TextOverride\OverridingTranslator;
use Nowo\TranslationYamlToolsBundle\TextOverride\TranslationOverrides;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use const JSON_THROW_ON_ERROR;

#[CoversClass(NowoTranslationYamlToolsExtension::class)]
final class OverridesExtensionTest extends TestCase
{
    public function testDisabledByDefault(): void
    {
        $container = new ContainerBuilder();
        (new NowoTranslationYamlToolsExtension())->load([[]], $container);

        self::assertFalse($container->getParameter('nowo_translation_yaml_tools.overrides.enabled'));
        self::assertFalse($container->getParameter('nowo_translation_yaml_tools.overrides.web_ui.enabled'));
        self::assertFalse($container->hasDefinition(OverridingTranslator::class));
        self::assertFalse($container->hasDefinition(TranslationOverrides::class));
    }

    public function testEnabledRegistersDecoratorSanitizerAndParameters(): void
    {
        $container = new ContainerBuilder();
        (new NowoTranslationYamlToolsExtension())->load([[
            'overrides' => [
                'editable'   => ['messages' => ['site.', 'site.'], 'ShopBundle' => ['loader.']],
                'locales'    => ['es', 'es', 'en'],
                'cache_pool' => 'cache.overrides',
                'cache_ttl'  => 60,
                'web_ui'     => ['security' => ['access_roles' => ['ROLE_EDITOR', '']]],
            ],
        ]], $container);

        self::assertTrue($container->getParameter('nowo_translation_yaml_tools.overrides.enabled'));
        self::assertSame(['messages' => ['site.'], 'ShopBundle' => ['loader.']], $container->getParameter('nowo_translation_yaml_tools.overrides.editable'));
        self::assertSame(['es', 'en'], $container->getParameter('nowo_translation_yaml_tools.overrides.locales'));
        self::assertSame(60, $container->getParameter('nowo_translation_yaml_tools.overrides.cache_ttl'));
        self::assertSame(['ROLE_EDITOR'], $container->getParameter('nowo_translation_yaml_tools.overrides.web_ui.security.access_roles'));
        self::assertSame('cache.overrides', (string) $container->getAlias('nowo_translation_yaml_tools.overrides.cache'));
        self::assertSame(SymfonyTranslationOverrideHtmlSanitizer::class, (string) $container->getAlias(TranslationOverrideHtmlSanitizerInterface::class));

        $decorator = $container->getDefinition(OverridingTranslator::class);
        self::assertSame(['translator', null, 5], $decorator->getDecoratedService());
        self::assertFalse($container->hasDefinition(TranslationOverrideController::class), 'Web UI stays off unless enabled.');
    }

    public function testCustomSanitizerAndWebUi(): void
    {
        $container = new ContainerBuilder();
        (new NowoTranslationYamlToolsExtension())->load([[
            'overrides' => [
                'enabled'        => true,
                'html_sanitizer' => 'app.my_sanitizer',
                'web_ui'         => ['enabled' => true, 'layout_template' => 'admin/layout.html.twig'],
            ],
        ]], $container);

        self::assertSame('app.my_sanitizer', (string) $container->getAlias(TranslationOverrideHtmlSanitizerInterface::class));
        self::assertTrue($container->hasDefinition(TranslationOverrideController::class));
        self::assertSame('admin/layout.html.twig', $container->getParameter('nowo_translation_yaml_tools.overrides.web_ui.layout_template'));
        self::assertTrue($container->getParameter('nowo_translation_yaml_tools.overrides.web_ui.enabled'));
    }

    public function testPrependsDoctrineMappingOnlyWhenEnabled(): void
    {
        foreach ([
            [[['overrides' => ['enabled' => true]]], true],
            [[['overrides' => null]], true],
            [[['overrides' => []]], true],
            [[['overrides' => true]], true],
            [[['overrides' => ['enabled' => true]], ['overrides' => false]], false],
            [[['overrides' => ['enabled' => false]]], false],
            [[[]], false],
        ] as [$configs, $expected]) {
            $fresh = new ContainerBuilder();
            $fresh->registerExtension(new DoctrineExtension());
            $fresh->registerExtension(new NowoTranslationYamlToolsExtension());
            foreach ($configs as $config) {
                $fresh->loadFromExtension('nowo_translation_yaml_tools', $config);
            }

            (new NowoTranslationYamlToolsExtension())->prepend($fresh);

            $mappings = [];
            foreach ($fresh->getExtensionConfig('doctrine') as $doctrine) {
                $mappings += $doctrine['orm']['mappings'] ?? [];
            }
            self::assertSame($expected, isset($mappings['NowoTranslationYamlToolsTextOverride']), json_encode($configs, JSON_THROW_ON_ERROR));
        }
    }
}
