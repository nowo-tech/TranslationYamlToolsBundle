<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * Configuration tree for nowo_translation_yaml_tools.
 */
final class Configuration implements ConfigurationInterface
{
    /**
     * {@inheritdoc}
     */
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('nowo_translation_yaml_tools');
        $root        = $treeBuilder->getRootNode();

        $root
            ->children()
                ->scalarNode('default_locale')
                    ->info('Override default source locale; null uses Symfony translator.default_locale / kernel.default_locale')
                    ->defaultNull()
                ->end()
                ->integerNode('yaml_tree_indent')
                    ->info('Spaces per indentation level when dumping nested YAML')
                    ->defaultValue(4)
                    ->min(2)
                    ->max(12)
                ->end()
                ->enumNode('machine_translator')
                    ->info('Machine translation backend used by nowo:translation-yaml:fill-missing')
                    ->values(['google', 'deepl', 'libretranslate'])
                    ->defaultValue('google')
                ->end()
                ->scalarNode('deepl_endpoint')
                    ->info('DeepL translate URL. Use https://api-free.deepl.com/v2/translate with a Free-plan auth key.')
                    ->defaultValue('https://api.deepl.com/v2/translate')
                ->end()
                ->scalarNode('libretranslate_base_url')
                    ->info('LibreTranslate server origin (no trailing path). Public demo is rate-limited; self-host for production.')
                    ->defaultValue('https://libretranslate.com')
                ->end()
                ->scalarNode('libretranslate_api_key')
                    ->info('Optional LibreTranslate API key (empty for public instances that do not require one).')
                    ->defaultValue('')
                ->end()
                ->arrayNode('machine_translation_locale_map')
                    ->info('Map Symfony locales to the exact language code sent to the active machine translator (Google, DeepL, LibreTranslate). Keys match case-insensitively; "-" and "_" are equivalent (e.g. pt_BR, pt-br). Example: pt_BR: pt-br')
                    ->normalizeKeys(false)
                    ->defaultValue([])
                    ->prototype('scalar')->end()
                ->end()
                ->arrayNode('machine_translator_by_locale')
                    ->info('Override machine_translator for specific Symfony locales: use google, deepl, or libretranslate. Target locale is checked first, then source, then machine_translator. Keys match like machine_translation_locale_map.')
                    ->normalizeKeys(false)
                    ->defaultValue([])
                    ->prototype('enum')
                        ->values(['google', 'deepl', 'libretranslate'])
                    ->end()
                ->end()
            ->end();

        return $treeBuilder;
    }
}
