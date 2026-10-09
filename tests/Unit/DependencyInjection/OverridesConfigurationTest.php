<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Tests\Unit\DependencyInjection;

use Nowo\TranslationYamlToolsBundle\DependencyInjection\Configuration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

#[CoversClass(Configuration::class)]
final class OverridesConfigurationTest extends TestCase
{
    public function testDefaultsAreOptIn(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [[]]);

        self::assertSame([
            'enabled'        => false,
            'table_prefix'   => 'nowo_translation_',
            'editable'       => [],
            'locales'        => [],
            'cache_pool'     => 'cache.app',
            'cache_ttl'      => 3600,
            'max_length'     => 5000,
            'html_sanitizer' => null,
            'web_ui'         => [
                'enabled'         => false,
                'path_prefix'     => '/_translation_yaml_tools/texts',
                'layout_template' => '@NowoTranslationYamlToolsBundle/text_override/layout.html.twig',
                'security'        => [
                    'access_roles'          => ['ROLE_ADMIN'],
                    'allow_unauthenticated' => false,
                ],
            ],
        ], $config['overrides']);
    }

    public function testEditableDomainsKeepTheirCase(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [[
            'overrides' => [
                'editable' => ['messages' => ['site.', 'legal.'], 'NowoUiKitBundle' => ['loader.'], 'all-of-it' => ['']],
                'locales'  => ['es', 'en'],
            ],
        ]]);

        self::assertTrue($config['overrides']['enabled'], 'canBeEnabled: a config block enables the feature.');
        self::assertSame(['messages' => ['site.', 'legal.'], 'NowoUiKitBundle' => ['loader.'], 'all-of-it' => ['']], $config['overrides']['editable']);
        self::assertSame(['es', 'en'], $config['overrides']['locales']);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    #[DataProvider('invalidOverrides')]
    public function testInvalidValuesAreRejected(array $overrides): void
    {
        $this->expectException(InvalidConfigurationException::class);
        (new Processor())->processConfiguration(new Configuration(), [['overrides' => $overrides]]);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidOverrides(): iterable
    {
        yield 'table prefix' => [['table_prefix' => 'Bad-Prefix']];
        yield 'long table prefix' => [['table_prefix' => str_repeat('a', 41)]];
        yield 'domain with colon' => [['editable' => ['a:b' => ['x.']]]];
        yield 'domain without prefixes' => [['editable' => ['messages' => []]]];
        yield 'path prefix' => [['web_ui' => ['path_prefix' => 'texts']]];
        yield 'max length' => [['max_length' => 0]];
    }
}
