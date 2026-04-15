<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Tests\Unit\DependencyInjection;

use Nowo\TranslationYamlToolsBundle\DependencyInjection\Configuration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

#[CoversClass(Configuration::class)]
final class ConfigurationTest extends TestCase
{
    public function testDefaultConfiguration(): void
    {
        $processor = new Processor();
        $config    = $processor->processConfiguration(new Configuration(), [[]]);

        self::assertNull($config['default_locale']);
        self::assertSame(4, $config['yaml_tree_indent']);
        self::assertSame('index', $config['yaml_tree_leaf_prefix_suffix']);
        self::assertSame('google', $config['machine_translator']);
        self::assertSame('https://api.deepl.com/v2/translate', $config['deepl_endpoint']);
        self::assertSame('https://libretranslate.com', $config['libretranslate_base_url']);
        self::assertSame('', $config['libretranslate_api_key']);
        self::assertSame([], $config['machine_translation_locale_map']);
        self::assertSame([], $config['machine_translator_by_locale']);
        self::assertSame([
            'enabled'                => false,
            'table_prefix'           => 'nowo_translation_',
            'record_call_site'       => true,
            'async_persist'          => false,
            'async_persist_strategy' => 'messenger',
            'web_ui'                 => [
                'enabled'         => false,
                'path_prefix'     => '/_translation_yaml_tools/missing-log',
                'layout_template' => '@NowoTranslationYamlToolsBundle/missing_translation_log/layout.html.twig',
            ],
        ], $config['missing_translation_log']);
    }

    public function testMissingTranslationLogFalseNormalizesToDisabledDefaults(): void
    {
        $processor = new Processor();
        $config    = $processor->processConfiguration(new Configuration(), [[
            'missing_translation_log' => false,
        ]]);

        self::assertIsArray($config['missing_translation_log']);
        self::assertFalse($config['missing_translation_log']['enabled']);
    }

    public function testInvalidTablePrefixThrows(): void
    {
        $processor = new Processor();
        $this->expectException(InvalidConfigurationException::class);
        $processor->processConfiguration(new Configuration(), [[
            'missing_translation_log' => [
                'enabled'      => true,
                'table_prefix' => 'Bad-Prefix',
            ],
        ]]);
    }

    public function testInvalidYamlTreeLeafPrefixSuffixThrows(): void
    {
        $processor = new Processor();
        $this->expectException(InvalidConfigurationException::class);
        $processor->processConfiguration(new Configuration(), [[
            'yaml_tree_leaf_prefix_suffix' => 'bad.dot',
        ]]);
    }

    public function testCustomConfiguration(): void
    {
        $processor = new Processor();
        $config    = $processor->processConfiguration(new Configuration(), [[
            'default_locale'               => 'fr',
            'yaml_tree_indent'             => 2,
            'machine_translator'           => 'google',
            'yaml_tree_leaf_prefix_suffix' => 'caption',
        ]]);

        self::assertSame('fr', $config['default_locale']);
        self::assertSame(2, $config['yaml_tree_indent']);
        self::assertSame('caption', $config['yaml_tree_leaf_prefix_suffix']);
    }

    public function testDeeplConfiguration(): void
    {
        $processor = new Processor();
        $config    = $processor->processConfiguration(new Configuration(), [[
            'machine_translator' => 'deepl',
            'deepl_endpoint'     => 'https://api-free.deepl.com/v2/translate',
        ]]);

        self::assertSame('deepl', $config['machine_translator']);
        self::assertSame('https://api-free.deepl.com/v2/translate', $config['deepl_endpoint']);
    }

    public function testLibretranslateConfiguration(): void
    {
        $processor = new Processor();
        $config    = $processor->processConfiguration(new Configuration(), [[
            'machine_translator'      => 'libretranslate',
            'libretranslate_base_url' => 'https://translate.local',
            'libretranslate_api_key'  => 'k',
        ]]);

        self::assertSame('libretranslate', $config['machine_translator']);
        self::assertSame('https://translate.local', $config['libretranslate_base_url']);
        self::assertSame('k', $config['libretranslate_api_key']);
    }

    public function testMachineTranslatorByLocale(): void
    {
        $processor = new Processor();
        $config    = $processor->processConfiguration(new Configuration(), [[
            'machine_translator'           => 'google',
            'machine_translator_by_locale' => [
                'pt_BR' => 'libretranslate',
            ],
        ]]);

        self::assertSame(['pt_BR' => 'libretranslate'], $config['machine_translator_by_locale']);
    }

    public function testMachineTranslationLocaleMap(): void
    {
        $processor = new Processor();
        $config    = $processor->processConfiguration(new Configuration(), [[
            'machine_translation_locale_map' => [
                'pt_BR' => 'pt-br',
                'zh-CN' => 'zh-Hans',
            ],
        ]]);

        self::assertSame(['pt_BR' => 'pt-br', 'zh-CN' => 'zh-Hans'], $config['machine_translation_locale_map']);
    }

    public function testYamlTreeIndentMustBeInRange(): void
    {
        $processor = new Processor();
        $this->expectException(InvalidConfigurationException::class);
        $processor->processConfiguration(new Configuration(), [[
            'yaml_tree_indent' => 1,
        ]]);
    }
}
