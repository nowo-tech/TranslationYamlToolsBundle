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
        self::assertSame('google', $config['machine_translator']);
        self::assertSame('https://api.deepl.com/v2/translate', $config['deepl_endpoint']);
        self::assertSame('https://libretranslate.com', $config['libretranslate_base_url']);
        self::assertSame('', $config['libretranslate_api_key']);
    }

    public function testCustomConfiguration(): void
    {
        $processor = new Processor();
        $config    = $processor->processConfiguration(new Configuration(), [[
            'default_locale'     => 'fr',
            'yaml_tree_indent'   => 2,
            'machine_translator' => 'google',
        ]]);

        self::assertSame('fr', $config['default_locale']);
        self::assertSame(2, $config['yaml_tree_indent']);
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
            'machine_translator'       => 'libretranslate',
            'libretranslate_base_url'  => 'https://translate.local',
            'libretranslate_api_key'   => 'k',
        ]]);

        self::assertSame('libretranslate', $config['machine_translator']);
        self::assertSame('https://translate.local', $config['libretranslate_base_url']);
        self::assertSame('k', $config['libretranslate_api_key']);
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
