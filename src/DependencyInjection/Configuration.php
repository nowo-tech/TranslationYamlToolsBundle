<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

use function is_string;
use function strlen;

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
                    ->info('Override default source locale; null uses translator.default_locale if defined, else kernel.default_locale, else en (Symfony 8 may only expose kernel.default_locale)')
                    ->defaultNull()
                ->end()
                ->integerNode('yaml_tree_indent')
                    ->info('Spaces per indentation level when dumping nested YAML')
                    ->defaultValue(4)
                    ->min(2)
                    ->max(12)
                ->end()
                ->scalarNode('yaml_tree_leaf_prefix_suffix')
                    ->info('Final segment appended to a conflicting leaf when using nowo:translation-yaml:tree --fix-leaf-prefix (e.g. "index" renames key "a" to "a.index")')
                    ->defaultValue('index')
                    ->validate()
                        ->ifTrue(static fn ($v): bool => !is_string($v) || $v === '' || str_contains($v, '.') || !preg_match('/^[a-zA-Z0-9_-]+$/', $v))
                        ->thenInvalid('yaml_tree_leaf_prefix_suffix must be a non-empty single segment without dots, matching [a-zA-Z0-9_-]+')
                    ->end()
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
                ->arrayNode('missing_translation_log')
                    ->info('Record runtime missing keys (id, domain, locale) in Doctrine table {table_prefix}missing_log; decorate translator and flush on kernel terminate')
                    ->canBeDisabled()
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')
                            ->defaultFalse()
                        ->end()
                        ->scalarNode('table_prefix')
                            ->info('Physical table name = prefix + "missing_log" (e.g. nowo_translation_ → nowo_translation_missing_log). Allowed: lowercase letters, digits, underscore; max 40 chars.')
                            ->defaultValue('nowo_translation_')
                            ->validate()
                                ->ifTrue(static fn ($v): bool => !is_string($v) || $v === '' || !preg_match('/^[a-z0-9_]+$/', $v))
                                ->thenInvalid('table_prefix must be a non-empty string matching [a-z0-9_]+')
                            ->end()
                            ->validate()
                                ->ifTrue(static fn ($v): bool => strlen((string) $v) > 40)
                                ->thenInvalid('table_prefix must be at most 40 characters')
                            ->end()
                        ->end()
                        ->booleanNode('record_call_site')
                            ->info('When true, store the first plausible caller file:line (debug_backtrace) per row; updates on new hits when a path is resolved. Disable to reduce overhead.')
                            ->defaultTrue()
                        ->end()
                        ->booleanNode('record_request_context')
                            ->info('When true and an HTTP Request exists, persist request_route, request_method, and request_path on the missing_log row (CLI has none). Disable for privacy or shorter rows.')
                            ->defaultTrue()
                        ->end()
                        ->booleanNode('async_persist')
                            ->info('When true, flush delegates persistence (see async_persist_strategy) instead of calling Doctrine immediately in the recorder. With strategy messenger, requires symfony/messenger and a default bus. With event_dispatcher, uses the app event_dispatcher and optional builtin listener.')
                            ->defaultFalse()
                        ->end()
                        ->enumNode('async_persist_strategy')
                            ->values(['messenger', 'event_dispatcher'])
                            ->defaultValue('messenger')
                            ->info('Used when async_persist is true. messenger: dispatch MissingTranslationBufferMessage. event_dispatcher: dispatch MissingTranslationBufferEvent; a builtin listener persists last unless stopPropagation() was called (e.g. you enqueue and persist in a worker).')
                        ->end()
                        ->arrayNode('web_ui')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->booleanNode('enabled')
                                    ->info('Expose HTTP routes + Twig UI to list rows and mark pending entries as added (protect with firewall / access_control)')
                                    ->defaultFalse()
                                ->end()
                                ->scalarNode('path_prefix')
                                    ->info('URL prefix for imported routes (must start with /)')
                                    ->defaultValue('/_translation_yaml_tools/missing-log')
                                    ->validate()
                                        ->ifTrue(static fn ($v): bool => !is_string($v) || !str_starts_with($v, '/'))
                                        ->thenInvalid('path_prefix must be a string starting with /')
                                    ->end()
                                ->end()
                                ->scalarNode('layout_template')
                                    ->info('Twig layout extended by the missing-log UI (global nowo_translation_yaml_tools_missing_log_layout_template). Use @NowoTranslationYamlToolsBundle/missing_translation_log/layout_integrate_dashboard_menu.html.twig or layout_integrate_breadcrumb_kit.html.twig to match those dashboards.')
                                    ->defaultValue('@NowoTranslationYamlToolsBundle/missing_translation_log/layout.html.twig')
                                ->end()
                                ->scalarNode('required_role')
                                    ->info('Symfony role required to access the Web UI (requires SecurityBundle). Set null to disable bundle-level checks.')
                                    ->defaultValue('ROLE_ADMIN')
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end();

        return $treeBuilder;
    }
}
