<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

use function array_key_exists;
use function is_array;
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
                ->integerNode('machine_translation_min_interval_ms')
                    ->info('Minimum delay between machine-translation HTTP calls (0 = no pacing). Useful for public LibreTranslate / API quotas.')
                    ->defaultValue(0)
                    ->min(0)
                    ->max(60_000)
                ->end()
                ->integerNode('machine_translation_max_requests_per_run')
                    ->info('Max string translations per fill-missing run (0 = unlimited). Prevents accidental bulk API burn.')
                    ->defaultValue(0)
                    ->min(0)
                ->end()
                ->floatNode('machine_translation_http_timeout')
                    ->info('HTTP timeout in seconds for Google / DeepL / LibreTranslate requests.')
                    ->defaultValue(30.0)
                    ->min(1.0)
                    ->max(300.0)
                ->end()
                ->scalarNode('deepl_endpoint')
                    ->info('DeepL translate URL. Use https://api-free.deepl.com/v2/translate with a Free-plan auth key.')
                    ->defaultValue('https://api.deepl.com/v2/translate')
                ->end()
                ->scalarNode('libretranslate_base_url')
                    ->info('LibreTranslate server origin (no trailing path). Host must be listed in libretranslate_allowed_hosts.')
                    ->defaultValue('https://libretranslate.com')
                ->end()
                ->scalarNode('libretranslate_api_key')
                    ->info('Optional LibreTranslate API key (empty for public instances that do not require one).')
                    ->defaultValue('')
                ->end()
                ->arrayNode('libretranslate_allowed_hosts')
                    ->info('Hostname allowlist for libretranslate_base_url (SSRF mitigation). Subdomains of a listed host are allowed.')
                    ->scalarPrototype()->end()
                    ->defaultValue(['libretranslate.com'])
                ->end()
                ->booleanNode('libretranslate_allow_http')
                    ->info('When true, allows http:// LibreTranslate URLs (local/dev only). Default https-only.')
                    ->defaultFalse()
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
                            ->beforeNormalization()
                                ->ifArray()
                                ->then(static function (array $v): array {
                                    $security = is_array($v['security'] ?? null) ? $v['security'] : [];

                                    // BC: required_role (scalar|null) → security.access_roles
                                    if (array_key_exists('required_role', $v) && !array_key_exists('access_roles', $security)) {
                                        $role = $v['required_role'];
                                        if ($role === null || $role === '') {
                                            $security['access_roles'] = [];
                                        } elseif (is_string($role)) {
                                            $security['access_roles'] = [$role];
                                        }
                                    }

                                    // BC: top-level allow_unauthenticated → security.allow_unauthenticated
                                    if (array_key_exists('allow_unauthenticated', $v) && !array_key_exists('allow_unauthenticated', $security)) {
                                        $security['allow_unauthenticated'] = (bool) $v['allow_unauthenticated'];
                                    }

                                    $v['security'] = $security;

                                    return $v;
                                })
                            ->end()
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
                                ->enumNode('css_framework')
                                    ->info('Host CSS stack hint for the missing-log Web UI (REQ-UI-001). Twig global nowo_translation_yaml_tools_css_framework. Demo default: bootstrap5.')
                                    ->values(['bootstrap', 'bootstrap4', 'bootstrap5', 'tailwind', 'foundation', 'custom', 'tabler', 'none'])
                                    ->defaultValue('bootstrap5')
                                ->end()
                                ->scalarNode('required_role')
                                    ->info('Deprecated BC alias for security.access_roles (single role). Prefer security.access_roles. Empty/null is fail-closed via the access checker.')
                                    ->defaultValue('ROLE_ADMIN')
                                ->end()
                                ->booleanNode('allow_unauthenticated')
                                    ->info('Deprecated BC alias for security.allow_unauthenticated. DEV/DEMO ONLY.')
                                    ->defaultFalse()
                                ->end()
                                ->arrayNode('security')
                                    ->info('Private Web UI access (REQ-UI-002). Defaults to ROLE_ADMIN; demos may set allow_unauthenticated.')
                                    ->addDefaultsIfNotSet()
                                    ->children()
                                        ->arrayNode('access_roles')
                                            ->scalarPrototype()->end()
                                            ->defaultValue(['ROLE_ADMIN'])
                                            ->info('User must be granted at least one role. Empty list is fail-closed (deny via access checker). Use allow_unauthenticated for demos only.')
                                        ->end()
                                        ->scalarNode('access_checker')
                                            ->defaultNull()
                                            ->info('Optional service id implementing MissingLogUiAccessCheckerInterface. null = built-in role checker.')
                                        ->end()
                                        ->booleanNode('allow_unauthenticated')
                                            ->defaultFalse()
                                            ->info('DEV/DEMO only: allow Web UI without SecurityBundle / without login. Never true in production.')
                                        ->end()
                                    ->end()
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->append($this->overridesNode())
            ->end();

        return $treeBuilder;
    }

    /**
     * Operator text overrides (DB rows served before the catalogues) + optional Web UI.
     */
    private function overridesNode(): ArrayNodeDefinition
    {
        /** @var ArrayNodeDefinition $node */
        $node = (new TreeBuilder('overrides'))->getRootNode();

        $node
            ->info('Operator text overrides: per-locale DB replacements for editable catalogue messages, applied by a translator decorator. Requires doctrine/orm + doctrine/doctrine-bundle; the host generates the migration for table {table_prefix}override.')
            ->canBeEnabled()
            ->children()
                ->scalarNode('table_prefix')
                    ->info('Physical table name = prefix + "override" (e.g. nowo_translation_override). Allowed: [a-z0-9_]+, max 40 chars.')
                    ->defaultValue('nowo_translation_')
                    ->validate()
                        ->ifTrue(static fn ($v): bool => !is_string($v) || $v === '' || strlen($v) > 40 || !preg_match('/^[a-z0-9_]+$/', $v))
                        ->thenInvalid('overrides.table_prefix must be a non-empty string matching [a-z0-9_]+ (max 40 characters)')
                    ->end()
                ->end()
                ->arrayNode('editable')
                    ->info('Editable messages: translation domain => list of key prefixes ("" = whole domain). Example: { messages: ["site.", "legal."], NowoUiKitBundle: ["loader."] }. Keys outside these prefixes cannot be overridden from the UI.')
                    ->useAttributeAsKey('domain')
                    ->normalizeKeys(false)
                    ->arrayPrototype()
                        ->scalarPrototype()->end()
                    ->end()
                    ->defaultValue([])
                    ->validate()
                        ->ifTrue(static function (array $v): bool {
                            foreach ($v as $domain => $prefixes) {
                                if (!is_string($domain) || $domain === '' || str_contains($domain, ':') || $prefixes === []) {
                                    return true;
                                }
                            }

                            return false;
                        })
                        ->thenInvalid('overrides.editable: each domain must be a non-empty name without ":" and list at least one key prefix ("" = whole domain)')
                    ->end()
                ->end()
                ->arrayNode('locales')
                    ->info('Locales edited in the UI (one tab each). Empty = framework.enabled_locales, else the default locale only. The default locale is always first.')
                    ->scalarPrototype()->end()
                    ->defaultValue([])
                ->end()
                ->scalarNode('cache_pool')
                    ->info('Cache pool (Symfony\\Contracts\\Cache\\CacheInterface) holding the override map.')
                    ->defaultValue('cache.app')
                    ->cannotBeEmpty()
                ->end()
                ->integerNode('cache_ttl')
                    ->info('Seconds the override map stays cached; writes through the bundle invalidate it immediately (TTL bounds staleness after SQL / restore writes).')
                    ->defaultValue(3600)
                    ->min(0)
                ->end()
                ->integerNode('max_length')
                    ->info('Maximum characters per override text (Web UI validation).')
                    ->defaultValue(5000)
                    ->min(1)
                ->end()
                ->scalarNode('html_sanitizer')
                    ->info('Service id implementing TranslationOverrideHtmlSanitizerInterface. null = symfony/html-sanitizer inline allowlist when installed, else strip all tags.')
                    ->defaultNull()
                ->end()
                ->arrayNode('web_ui')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')
                            ->info('Expose the overrides desk (list / edit / reset). Import routes/translation_override_ui.yaml. Requires twig-bundle, security-csrf (+ framework.csrf_protection) and security-bundle.')
                            ->defaultFalse()
                        ->end()
                        ->scalarNode('path_prefix')
                            ->info('URL prefix for imported routes (must start with /)')
                            ->defaultValue('/_translation_yaml_tools/texts')
                            ->validate()
                                ->ifTrue(static fn ($v): bool => !is_string($v) || !str_starts_with($v, '/'))
                                ->thenInvalid('overrides.web_ui.path_prefix must be a string starting with /')
                            ->end()
                        ->end()
                        ->scalarNode('layout_template')
                            ->info('Twig layout the pages extend; must define blocks "title" and "body" (Symfony base.html.twig convention), e.g. your admin layout.')
                            ->defaultValue('@NowoTranslationYamlToolsBundle/text_override/layout.html.twig')
                            ->cannotBeEmpty()
                        ->end()
                        ->arrayNode('security')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->arrayNode('access_roles')
                                    ->info('User must be granted at least one role (any attribute isGranted() understands). Empty list = deny everyone.')
                                    ->scalarPrototype()->end()
                                    ->defaultValue(['ROLE_ADMIN'])
                                ->end()
                                ->booleanNode('allow_unauthenticated')
                                    ->info('DEV/DEMO only: skip the role check (no SecurityBundle needed). Never true in production.')
                                    ->defaultFalse()
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end();

        return $node;
    }
}
