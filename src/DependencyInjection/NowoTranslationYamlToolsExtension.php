<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\DependencyInjection;

use InvalidArgumentException;
use Nowo\TranslationYamlToolsBundle\DependencyInjection\Compiler\EntityManagerOptionPass;
use Nowo\TranslationYamlToolsBundle\DependencyInjection\Compiler\PrivateEntityManagerPass;
use Nowo\TranslationYamlToolsBundle\Doctrine\PrivateEntityManagerFlushBridge;
use Nowo\TranslationYamlToolsBundle\Doctrine\PrivateEntityManagerMiddleware;
use Nowo\TranslationYamlToolsBundle\Doctrine\TranslationYamlToolsEntityManager;
use Nowo\TranslationYamlToolsBundle\Doctrine\TranslationYamlToolsManagerRegistry;
use Nowo\TranslationYamlToolsBundle\Entity\MissingTranslationLog;
use Nowo\TranslationYamlToolsBundle\EventSubscriber\EntityManagerBoundarySubscriber;
use Nowo\TranslationYamlToolsBundle\MachineTranslation\LibreTranslateBaseUrlGuard;
use Nowo\TranslationYamlToolsBundle\MachineTranslation\MachineTranslationLocaleMapper;
use Nowo\TranslationYamlToolsBundle\Repository\MissingTranslationLogRepository;
use Nowo\TranslationYamlToolsBundle\Security\ConfigurableMissingLogUiAccessChecker;
use Nowo\TranslationYamlToolsBundle\Security\MissingLogUiAccessCheckerInterface;
use Nowo\TranslationYamlToolsBundle\TextOverride\Entity\TranslationOverride;
use Nowo\TranslationYamlToolsBundle\TextOverride\Html\StripTagsTranslationOverrideHtmlSanitizer;
use Nowo\TranslationYamlToolsBundle\TextOverride\Html\SymfonyTranslationOverrideHtmlSanitizer;
use Nowo\TranslationYamlToolsBundle\TextOverride\Html\TranslationOverrideHtmlSanitizerInterface;
use Nowo\TranslationYamlToolsBundle\TextOverride\Repository\TranslationOverrideRepository;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\Argument\IteratorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\Messenger\MessageBusInterface;

use function array_key_exists;
use function array_keys;
use function array_unique;
use function array_values;
use function implode;
use function in_array;
use function is_array;
use function is_bool;
use function is_string;
use function sprintf;

/**
 * Loads services and parameters for Translation YAML Tools.
 */
final class NowoTranslationYamlToolsExtension extends Extension implements PrependExtensionInterface
{
    /** FormKit profile name used by every Web UI form type ({@code #[FormKitConfig]}). */
    public const FORM_KIT_PROFILE = 'translation_yaml_tools';

    /**
     * FormKit profile prepended as {@code nowo_form_kit.profiles.translation_yaml_tools} (REQ-TWIG-005 rule 11).
     *
     * Framework-neutral on purpose (REQ-UI-003): the active form theme (host {@code twig.form_themes}, optionally
     * {@code web_ui.form_themes}) adds the CSS framework classes. The form types pass explicit label / help keys, so
     * the convention placeholder / help keys are off. A host overrides the whole profile by defining
     * {@code nowo_form_kit.profiles.translation_yaml_tools} itself (e.g. to add {@code defaults.attr.class}).
     *
     * @var array<string, mixed>
     */
    public const FORM_KIT_PROFILE_CONFIG = [
        'alias'              => self::FORM_KIT_PROFILE,
        'translation_domain' => 'NowoTranslationYamlToolsBundle',
        'auto_placeholder'   => false,
        'auto_help'          => false,
        'defaults'           => [
            'attr'     => [],
            'row_attr' => [],
        ],
        'field_types' => [],
    ];

    /**
     * {@inheritdoc}
     */
    public function prepend(ContainerBuilder $container): void
    {
        if ($container->hasExtension('doctrine') && $this->rawConfigEnablesMissingTranslationLog($container)) {
            $container->prependExtensionConfig('doctrine', ['orm' => $this->ormMappings($container, [
                'NowoTranslationYamlToolsMissingLog' => [
                    'is_bundle' => false,
                    'type'      => 'attribute',
                    'dir'       => __DIR__ . '/../Entity',
                    'prefix'    => 'Nowo\\TranslationYamlToolsBundle\\Entity',
                ],
            ])]);
        }

        if ($container->hasExtension('doctrine') && $this->rawConfigEnablesOverrides($container)) {
            $container->prependExtensionConfig('doctrine', ['orm' => $this->ormMappings($container, [
                'NowoTranslationYamlToolsTextOverride' => [
                    'is_bundle' => false,
                    'type'      => 'attribute',
                    'dir'       => __DIR__ . '/../TextOverride/Entity',
                    'prefix'    => 'Nowo\\TranslationYamlToolsBundle\\TextOverride\\Entity',
                ],
            ])]);
        }

        $this->prependUiKitDefaults($container);
        $this->prependFormKitProfile($container);
    }

    /**
     * Registers the {@see self::FORM_KIT_PROFILE} FormKit profile unless the host already defines it.
     * FormKit is a required dependency; the guard only keeps kernels without NowoFormKitBundle bootable.
     */
    private function prependFormKitProfile(ContainerBuilder $container): void
    {
        if (!$container->hasExtension('nowo_form_kit')) {
            return;
        }

        foreach ($container->getExtensionConfig('nowo_form_kit') as $cfg) {
            $profiles = $cfg['profiles'] ?? null;
            if (is_array($profiles) && array_key_exists(self::FORM_KIT_PROFILE, $profiles)) {
                return;
            }
        }

        $container->prependExtensionConfig('nowo_form_kit', [
            'profiles' => [self::FORM_KIT_PROFILE => self::FORM_KIT_PROFILE_CONFIG],
        ]);
    }

    /**
     * When UiKit is installed, seed nowo_ui_kit from the deprecated missing_translation_log.web_ui.css_framework
     * (default bootstrap5, as in 1.x) so kit macros resolve the same stack. Does not override keys the host already
     * set (set nowo_ui_kit.css_framework / icon_set once for every bundle).
     */
    private function prependUiKitDefaults(ContainerBuilder $container): void
    {
        if (!$container->hasExtension('nowo_ui_kit')) {
            return;
        }

        $hostHasCssFramework = false;
        $hostHasIconSet      = false;
        foreach ($container->getExtensionConfig('nowo_ui_kit') as $cfg) {
            if (!is_array($cfg)) {
                continue;
            }
            if (array_key_exists('css_framework', $cfg)) {
                $hostHasCssFramework = true;
            }
            if (array_key_exists('icon_set', $cfg)) {
                $hostHasIconSet = true;
            }
        }

        if ($hostHasCssFramework && $hostHasIconSet) {
            return;
        }

        $config = $this->processConfiguration(new Configuration(), $container->getExtensionConfig($this->getAlias()));
        /** @var array<string, mixed> $webUi */
        $webUi    = $config['missing_translation_log']['web_ui'];
        $defaults = [];

        if (!$hostHasCssFramework) {
            $fw                        = (string) ($webUi['css_framework'] ?? 'bootstrap5');
            $defaults['css_framework'] = $fw === 'bootstrap' ? 'bootstrap5' : $fw;
        }
        if (!$hostHasIconSet) {
            $fwForIcons           = (string) ($defaults['css_framework'] ?? $webUi['css_framework'] ?? 'bootstrap5');
            $defaults['icon_set'] = $fwForIcons === 'tabler' ? 'tabler-icons' : 'bootstrap-icons';
        }

        if ($defaults !== []) {
            $container->prependExtensionConfig('nowo_ui_kit', $defaults);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config        = $this->processConfiguration($configuration, $configs);

        $container->setParameter('nowo_translation_yaml_tools.default_locale', $config['default_locale']);
        $container->setParameter('nowo_translation_yaml_tools.yaml_tree_indent', $config['yaml_tree_indent']);
        $container->setParameter('nowo_translation_yaml_tools.yaml_tree_leaf_prefix_suffix', $config['yaml_tree_leaf_prefix_suffix']);
        $container->setParameter('nowo_translation_yaml_tools.key_command.locales', array_values(array_unique(array_map('strval', $config['key_command']['locales']))));
        $container->setParameter('nowo_translation_yaml_tools.machine_translator', $config['machine_translator']);
        $container->setParameter('nowo_translation_yaml_tools.machine_translation_min_interval_ms', (int) $config['machine_translation_min_interval_ms']);
        $container->setParameter('nowo_translation_yaml_tools.machine_translation_max_requests_per_run', (int) $config['machine_translation_max_requests_per_run']);
        $container->setParameter('nowo_translation_yaml_tools.machine_translation_http_timeout', (float) $config['machine_translation_http_timeout']);
        $container->setParameter('nowo_translation_yaml_tools.deepl_endpoint', $config['deepl_endpoint']);
        $container->setParameter('nowo_translation_yaml_tools.libretranslate_base_url', $config['libretranslate_base_url']);
        $container->setParameter('nowo_translation_yaml_tools.libretranslate_api_key', $config['libretranslate_api_key']);
        /** @var list<string> $ltAllowedHosts */
        $ltAllowedHosts = array_values(array_map('strval', $config['libretranslate_allowed_hosts']));
        $ltAllowHttp    = (bool) $config['libretranslate_allow_http'];
        $container->setParameter('nowo_translation_yaml_tools.libretranslate_allowed_hosts', $ltAllowedHosts);
        $container->setParameter('nowo_translation_yaml_tools.libretranslate_allow_http', $ltAllowHttp);

        try {
            (new LibreTranslateBaseUrlGuard($ltAllowedHosts, $ltAllowHttp))
                ->assertAllowed((string) $config['libretranslate_base_url']);
        } catch (InvalidArgumentException $e) {
            throw new InvalidConfigurationException($e->getMessage(), 0, $e);
        }

        $localeMap = [];
        foreach ($config['machine_translation_locale_map'] as $symfonyLocale => $apiCode) {
            $localeMap[MachineTranslationLocaleMapper::canonicalLocaleKey((string) $symfonyLocale)] = (string) $apiCode;
        }
        $container->setParameter('nowo_translation_yaml_tools.machine_translation_locale_map', $localeMap);

        $byLocaleBackend = [];
        foreach ($config['machine_translator_by_locale'] as $symfonyLocale => $backend) {
            $byLocaleBackend[MachineTranslationLocaleMapper::canonicalLocaleKey((string) $symfonyLocale)] = (string) $backend;
        }
        $container->setParameter('nowo_translation_yaml_tools.machine_translator_by_locale', $byLocaleBackend);
        $container->setParameter(
            'nowo_translation_yaml_tools.machine_translator_per_locale',
            $byLocaleBackend !== [],
        );

        /** @var array<string, mixed> $missingLog */
        $missingLog      = $config['missing_translation_log'];
        $tablePrefix     = 'nowo_translation_';
        $webUiPathPrefix = '/_translation_yaml_tools/missing-log';

        $missingLogEnabled    = $this->literalBool($missingLog['enabled'] ?? false, 'missing_translation_log.enabled');
        $tablePrefix          = (string) ($missingLog['table_prefix'] ?? $tablePrefix);
        $recordCallSite       = (bool) ($missingLog['record_call_site'] ?? true);
        $recordRequestContext = (bool) ($missingLog['record_request_context'] ?? true);
        $asyncPersist         = (bool) ($missingLog['async_persist'] ?? false);
        $asyncPersistStrategy = (string) ($missingLog['async_persist_strategy'] ?? 'messenger');
        $webUi                = is_array($missingLog['web_ui'] ?? null) ? $missingLog['web_ui'] : [];
        $webUiEnabled         = $this->literalBool($webUi['enabled'] ?? false, 'missing_translation_log.web_ui.enabled');
        $webUiPathPrefix      = (string) ($webUi['path_prefix'] ?? $webUiPathPrefix);
        $webUiLayoutTemplate  = is_string($webUi['layout_template'] ?? null) && $webUi['layout_template'] !== '' ? $webUi['layout_template'] : null;
        /** @var array<string, string> $webUiTemplates */
        $webUiTemplates    = is_array($webUi['templates'] ?? null) ? $webUi['templates'] : [];
        $webUiCssFramework = (string) ($webUi['css_framework'] ?? 'bootstrap5');
        $security          = is_array($webUi['security'] ?? null) ? $webUi['security'] : [];
        /** @var list<string> $accessRoles */
        $accessRoles = [];
        foreach ($security['access_roles'] ?? ['ROLE_ADMIN'] as $role) {
            if (is_string($role) && $role !== '') {
                $accessRoles[] = $role;
            }
        }
        $accessCheckerId           = $security['access_checker'] ?? null;
        $customAccessChecker       = is_string($accessCheckerId) && $accessCheckerId !== '';
        $webUiAllowUnauthenticated = $this->literalBool($security['allow_unauthenticated'] ?? $webUi['allow_unauthenticated'] ?? false, 'missing_translation_log.web_ui.security.allow_unauthenticated');
        // BC parameter: first configured role, or null when bundle-level role checks are disabled.
        $webUiRequiredRoleBc = $accessRoles[0] ?? null;

        $container->setParameter('nowo_translation_yaml_tools.missing_translation_log.enabled', $missingLogEnabled);
        $container->setParameter('nowo_translation_yaml_tools.missing_translation_log.table_prefix', $tablePrefix);
        $container->setParameter('nowo_translation_yaml_tools.missing_translation_log.record_call_site', $recordCallSite);
        $container->setParameter('nowo_translation_yaml_tools.missing_translation_log.record_request_context', $recordRequestContext);
        $container->setParameter('nowo_translation_yaml_tools.missing_translation_log.async_persist', $asyncPersist);
        $container->setParameter('nowo_translation_yaml_tools.missing_translation_log.async_persist_strategy', $asyncPersistStrategy);
        $container->setParameter('nowo_translation_yaml_tools.missing_translation_log.web_ui.enabled', $webUiEnabled);
        $container->setParameter('nowo_translation_yaml_tools.missing_translation_log.web_ui.path_prefix', $webUiPathPrefix);
        $container->setParameter('nowo_translation_yaml_tools.missing_translation_log.web_ui.layout_template', $webUiLayoutTemplate);
        $container->setParameter('nowo_translation_yaml_tools.missing_translation_log.web_ui.templates', $webUiTemplates);
        $container->setParameter('nowo_translation_yaml_tools.missing_translation_log.web_ui.css_framework', $webUiCssFramework);
        $container->setParameter('nowo_translation_yaml_tools.missing_translation_log.web_ui.required_role', $webUiRequiredRoleBc);
        $container->setParameter('nowo_translation_yaml_tools.missing_translation_log.web_ui.allow_unauthenticated', $webUiAllowUnauthenticated);
        $container->setParameter('nowo_translation_yaml_tools.missing_translation_log.web_ui.security.access_roles', $accessRoles);
        $container->setParameter('nowo_translation_yaml_tools.missing_translation_log.web_ui.security.allow_unauthenticated', $webUiAllowUnauthenticated);
        $container->setParameter('nowo_translation_yaml_tools.missing_translation_log.web_ui.security.custom_access_checker', $customAccessChecker);

        // SecurityBundle presence + MissingLogUiAccessSubscriber: MissingLogWebUiSecurityPass
        // (Extension::load runs in an isolated container where other bundles are invisible).

        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.yaml');

        /** @var array<string, mixed> $overrides */
        $overrides = $config['overrides'];
        $this->loadOverrides($overrides, $container, $loader);
        // 1.17.0 (audit LD-TYT-M1/L1): the named manager must exist and map the bundle entities.
        $container->setParameter(EntityManagerOptionPass::PARAMETER, $missingLogEnabled || $overrides['enabled'] === true ? $config['doctrine']['entity_manager'] : null);

        if ($missingLogEnabled) {
            $loader->load('services_missing_translation.yaml');
            if ($asyncPersist && $asyncPersistStrategy === 'messenger' && interface_exists(MessageBusInterface::class)) {
                $loader->load('services_missing_translation_messenger.yaml');
            }
            if ($asyncPersist && $asyncPersistStrategy === 'event_dispatcher') {
                $loader->load('services_missing_translation_event_dispatcher.yaml');
            }
            if ($webUiEnabled) {
                $loader->load('services_missing_translation_web.yaml');
                if (!$webUiAllowUnauthenticated) {
                    $this->registerAccessChecker($container, $accessRoles, $customAccessChecker ? (string) $accessCheckerId : null);
                }
            }
        }

        $entityClasses = [];
        if ($missingLogEnabled) {
            $entityClasses[] = MissingTranslationLog::class;
        }
        if ($overrides['enabled'] === true) {
            $entityClasses[] = TranslationOverride::class;
        }
        if ($entityClasses !== []) {
            /** @var array{entity_manager: string|null, private_entity_manager: bool} $doctrine */
            $doctrine = $config['doctrine'];
            $this->registerEntityManagerBoundaries($container, $doctrine, $entityClasses);
        }
    }

    /**
     * 1.18.0 (REQ-ARCH-001 rule 6, REQ-RUNTIME-002 rule 4): the bundle never flushes or resets the application's
     * manager.
     *
     * - `doctrine.entity_manager` null (zero config): the repositories see {@see TranslationYamlToolsManagerRegistry},
     *   so they read and write the bundle rows through the private manager ({@see TranslationYamlToolsEntityManager})
     *   built on the default manager's connection and configuration. It is dropped at every outermost boundary and at
     *   `kernel.reset`; the rollback middleware goes on its connection only ({@see PrivateEntityManagerPass},
     *   doctrine/dbal 4); {@see PrivateEntityManagerFlushBridge} keeps the deprecated "flush: false + host flush"
     *   bridge working.
     * - `doctrine.private_entity_manager: false` (deprecated): the pre-1.18 behaviour on the default manager.
     * - `doctrine.entity_manager` set: {@see EntityManagerOptionPass} hands that manager to the boundary subscriber,
     *   unless it is the host's default manager.
     *
     * @param array{entity_manager: string|null, private_entity_manager: bool} $doctrine
     * @param list<class-string> $entityClasses
     */
    private function registerEntityManagerBoundaries(ContainerBuilder $container, array $doctrine, array $entityClasses): void
    {
        $subscriber = (new Definition(EntityManagerBoundarySubscriber::class))
            ->setArgument('$resettables', new IteratorArgument([]))
            ->setArgument('$entityManagers', new IteratorArgument([]))
            ->setArgument('$registries', new IteratorArgument([]))
            ->setArgument('$managerName', $doctrine['entity_manager'])
            ->setArgument('$logger', new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE))
            ->addTag('kernel.event_subscriber');
        $container->setDefinition(EntityManagerBoundarySubscriber::class, $subscriber);

        if ($doctrine['entity_manager'] !== null || $doctrine['private_entity_manager'] !== true) {
            return;
        }

        $container->register(TranslationYamlToolsEntityManager::class, TranslationYamlToolsEntityManager::class)
            ->setArguments([new Reference('doctrine'), $entityClasses, new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE)])
            ->addTag('kernel.reset', ['method' => 'reset']);
        $container->register(TranslationYamlToolsManagerRegistry::class, TranslationYamlToolsManagerRegistry::class)
            ->setArguments([new Reference('doctrine'), new Reference(TranslationYamlToolsEntityManager::class)]);
        foreach ([MissingTranslationLogRepository::class, TranslationOverrideRepository::class] as $repository) {
            if ($container->hasDefinition($repository)) {
                $container->getDefinition($repository)->setArgument('$registry', new Reference(TranslationYamlToolsManagerRegistry::class));
            }
        }
        if (PrivateEntityManagerPass::supportsRollbackMiddleware()) {
            $container->register(PrivateEntityManagerMiddleware::class, PrivateEntityManagerMiddleware::class)
                ->setArguments([new Reference(TranslationYamlToolsEntityManager::class)])
                ->addTag('doctrine.middleware');
        }

        $lazyPrivate = new IteratorArgument([new Reference(TranslationYamlToolsEntityManager::class, ContainerInterface::IGNORE_ON_UNINITIALIZED_REFERENCE)]);
        $subscriber->setArgument('$resettables', $lazyPrivate);
        $container->register(PrivateEntityManagerFlushBridge::class, PrivateEntityManagerFlushBridge::class)
            ->setArguments([$lazyPrivate, new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE)])
            ->addTag('doctrine.event_listener', ['event' => 'postFlush']);
    }

    /**
     * @param array<string, mixed> $overrides processed overrides config
     */
    private function loadOverrides(array $overrides, ContainerBuilder $container, YamlFileLoader $loader): void
    {
        $enabled = $this->literalBool($overrides['enabled'], 'overrides.enabled');
        /** @var array<string, mixed> $webUi */
        $webUi        = $overrides['web_ui'];
        $webUiEnabled = $this->literalBool($webUi['enabled'], 'overrides.web_ui.enabled') && $enabled;
        /** @var array<string, mixed> $security */
        $security             = $webUi['security'];
        $allowUnauthenticated = $this->literalBool($security['allow_unauthenticated'], 'overrides.web_ui.security.allow_unauthenticated');

        $editable = [];
        /** @var array<string, list<string>> $configuredEditable */
        $configuredEditable = $overrides['editable'];
        foreach ($configuredEditable as $domain => $prefixes) {
            $editable[(string) $domain] = array_values(array_unique(array_map('strval', $prefixes)));
        }
        $locales = [];
        /** @var list<string> $configuredLocales */
        $configuredLocales = $overrides['locales'];
        foreach ($configuredLocales as $locale) {
            if (is_string($locale) && $locale !== '' && !in_array($locale, $locales, true)) {
                $locales[] = $locale;
            }
        }
        $accessRoles = [];
        /** @var list<string> $configuredRoles */
        $configuredRoles = $security['access_roles'];
        foreach ($configuredRoles as $role) {
            if (is_string($role) && $role !== '') {
                $accessRoles[] = $role;
            }
        }

        $prefix = 'nowo_translation_yaml_tools.overrides.';
        $container->setParameter($prefix . 'enabled', $enabled);
        $container->setParameter($prefix . 'table_prefix', (string) $overrides['table_prefix']);
        $container->setParameter($prefix . 'editable', $editable);
        $container->setParameter($prefix . 'locales', $locales);
        $container->setParameter($prefix . 'cache_ttl', (int) $overrides['cache_ttl']);
        $container->setParameter($prefix . 'max_length', (int) $overrides['max_length']);
        $container->setParameter($prefix . 'web_ui.enabled', $webUiEnabled);
        $container->setParameter($prefix . 'web_ui.path_prefix', (string) $webUi['path_prefix']);
        $layoutTemplate = $webUi['layout_template'];
        $container->setParameter($prefix . 'web_ui.layout_template', is_string($layoutTemplate) && $layoutTemplate !== '' ? $layoutTemplate : null);
        $container->setParameter($prefix . 'web_ui.templates', $webUi['templates']);
        $container->setParameter($prefix . 'web_ui.form_themes', array_values($webUi['form_themes']));
        $container->setParameter($prefix . 'web_ui.security.access_roles', $accessRoles);
        $container->setParameter($prefix . 'web_ui.security.allow_unauthenticated', $allowUnauthenticated);

        if (!$enabled) {
            return;
        }

        $container->setAlias($prefix . 'cache', (string) $overrides['cache_pool']);

        $sanitizerId = $overrides['html_sanitizer'];
        if (!is_string($sanitizerId) || $sanitizerId === '') {
            // symfony/html-sanitizer when installed, else strip every tag.
            $sanitizerId = class_exists(HtmlSanitizer::class) ? SymfonyTranslationOverrideHtmlSanitizer::class : StripTagsTranslationOverrideHtmlSanitizer::class;
            $container->setDefinition($sanitizerId, new Definition($sanitizerId));
        }
        $container->setAlias(TranslationOverrideHtmlSanitizerInterface::class, $sanitizerId);

        $loader->load('services_overrides.yaml');
        if ($webUiEnabled) {
            $loader->load('services_overrides_web.yaml');
        }
    }

    /**
     * @param list<string> $accessRoles
     */
    private function registerAccessChecker(ContainerBuilder $container, array $accessRoles, ?string $accessCheckerId): void
    {
        if ($accessCheckerId === null || $accessCheckerId === '') {
            $accessCheckerId = 'nowo_translation_yaml_tools.missing_log_ui.access_checker.default';
            $definition      = new Definition(ConfigurableMissingLogUiAccessChecker::class);
            $definition->setArgument('$accessRoles', $accessRoles);
            $hasAuthorizationChecker = $container->hasDefinition('security.authorization_checker')
                || $container->hasAlias('security.authorization_checker');
            if ($hasAuthorizationChecker) {
                $definition->setArgument('$authorizationChecker', new Reference('security.authorization_checker'));
            } else {
                // Placeholder until SecurityBundle registers the checker; SecurityPass fails compile if still missing.
                $definition->setAutowired(true);
            }
            $container->setDefinition($accessCheckerId, $definition);
        }

        $container->setAlias(MissingLogUiAccessCheckerInterface::class, $accessCheckerId);
    }

    /**
     * REQ-SEC-004 (L1): the switches that open a Web UI (or skip its access check) must be literal booleans. An env
     * placeholder (e.g. "%env(bool:UI_PUBLIC)%") reaches load() as a placeholder string, which a (bool) cast would
     * turn into true whatever the env value: refuse it instead of failing open.
     *
     * @throws InvalidConfigurationException when the value is not a boolean
     */
    private function literalBool(mixed $value, string $path): bool
    {
        if (!is_bool($value)) {
            throw new InvalidConfigurationException(sprintf('nowo_translation_yaml_tools.%s must be a literal boolean (true / false); env placeholders and other values are not supported for this security-relevant switch.', $path));
        }

        return $value;
    }

    /**
     * 1.17.0: `doctrine.entity_manager` maps the bundle entities on that manager only (rule 6: the bundle's own
     * manager); null keeps them on the default manager.
     *
     * @param array<string, array<string, mixed>> $mappings
     *
     * @return array<string, mixed>
     */
    private function ormMappings(ContainerBuilder $container, array $mappings): array
    {
        $entityManager = null;
        foreach ($container->getExtensionConfig('nowo_translation_yaml_tools') as $chunk) {
            $name = is_array($chunk) && is_array($chunk['doctrine'] ?? null) ? ($chunk['doctrine']['entity_manager'] ?? null) : null;
            if (is_string($name) && $name !== '') {
                $entityManager = $name;
            }
        }

        if ($entityManager === null) {
            return ['mappings' => $mappings];
        }

        // Audit LD-TYT-L2: the returned config declares entity_managers.<name> itself, so an undeclared (mistyped) name
        // would silently become a new manager on the default connection. The host must declare it.
        $declared = $this->hostEntityManagerNames($container);
        if (!in_array($entityManager, $declared, true)) {
            throw new InvalidConfigurationException(sprintf('nowo_translation_yaml_tools.doctrine.entity_manager is "%s", but the host Doctrine configuration does not declare that entity manager (declared: "%s"). Add it under doctrine.orm.entity_managers (with its own connection) or fix the name.', $entityManager, implode('", "', $declared)));
        }

        // 1.17.0: the host's default manager stays the default (LD-TYT-M1); EntityManagerOptionPass removes the
        // entities from every other manager, including an auto-mapped one (LD-TYT-L1).
        return [
            'default_entity_manager' => $this->hostDefaultEntityManager($container),
            'entity_managers'        => [$entityManager => ['mappings' => $mappings]],
        ];
    }

    /**
     * @return list<string> the entity manager names the host declares under `doctrine.orm.entity_managers` (or
     *                      `default` for the shorthand), read before the bundle prepends its manager
     */
    private function hostEntityManagerNames(ContainerBuilder $container): array
    {
        $names = [];
        foreach ($container->getExtensionConfig('doctrine') as $config) {
            if (!is_array($config['orm'] ?? null)) {
                continue;
            }
            // The shorthand (no entity_managers key) configures the "default" manager.
            $managers = is_array($config['orm']['entity_managers'] ?? null) ? $config['orm']['entity_managers'] : ['default' => []];
            foreach (array_keys($managers) as $name) {
                $names[] = (string) $name;
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * The host's default entity manager, resolved from the host's own `doctrine` configuration before the bundle
     * prepends its manager. Without it, a host that leaves `default_entity_manager` unset (Flex shorthand included)
     * would get the bundle's manager as its default, because DoctrineBundle takes the first configured manager
     * (audit LD-TYT-M1).
     */
    private function hostDefaultEntityManager(ContainerBuilder $container): string
    {
        $default = null;
        $first   = null;
        foreach ($container->getExtensionConfig('doctrine') as $config) {
            $orm = is_array($config['orm'] ?? null) ? $config['orm'] : null;
            if ($orm === null) {
                continue;
            }
            if (is_string($orm['default_entity_manager'] ?? null) && $orm['default_entity_manager'] !== '') {
                $default = $orm['default_entity_manager'];
            }
            if (!is_array($orm['entity_managers'] ?? null)) {
                $first ??= 'default';

                continue;
            }
            foreach (array_keys($orm['entity_managers']) as $name) {
                $first ??= (string) $name;

                break;
            }
        }

        return $default ?? $first ?? 'default';
    }

    private function rawConfigEnablesOverrides(ContainerBuilder $container): bool
    {
        $enabled = false;
        foreach ($container->getExtensionConfig('nowo_translation_yaml_tools') as $chunk) {
            if (!is_array($chunk) || !array_key_exists('overrides', $chunk)) {
                continue;
            }
            $overrides = $chunk['overrides'];
            if ($overrides === null || is_bool($overrides)) {
                $enabled = $overrides ?? true;
            } elseif (is_array($overrides)) {
                $enabled = !array_key_exists('enabled', $overrides) || $overrides['enabled'] === true;
            }
        }

        return $enabled;
    }

    /**
     * Whether the processed config will have missing_translation_log.enabled = true, decided before load().
     *
     * Only the `enabled` switch of each raw chunk is run through the bundle Configuration, so the result follows
     * the same canBeDisabled() rules as load() (`~` / `true` enable, `false` disables, array semantics of the
     * installed symfony/config version, later chunks win) without validating the rest of the raw config.
     * A non-boolean `enabled` (e.g. an unresolved env placeholder) is treated as disabled.
     */
    private function rawConfigEnablesMissingTranslationLog(ContainerBuilder $container): bool
    {
        $switches = [];
        foreach ($container->getExtensionConfig('nowo_translation_yaml_tools') as $chunk) {
            if (!is_array($chunk) || !array_key_exists('missing_translation_log', $chunk)) {
                continue;
            }
            $missingLog = $chunk['missing_translation_log'];
            if ($missingLog === null || is_bool($missingLog)) {
                $switches[] = ['missing_translation_log' => $missingLog];
            } elseif (is_array($missingLog)) {
                $switches[] = ['missing_translation_log' => array_key_exists('enabled', $missingLog)
                    ? ['enabled' => $missingLog['enabled'] === true]
                    : []];
            }
        }

        if ($switches === []) {
            return false;
        }

        $config = $this->processConfiguration(new Configuration(), $switches);

        return $config['missing_translation_log']['enabled'] === true;
    }

    /**
     * {@inheritdoc}
     */
    public function getAlias(): string
    {
        return 'nowo_translation_yaml_tools';
    }
}
