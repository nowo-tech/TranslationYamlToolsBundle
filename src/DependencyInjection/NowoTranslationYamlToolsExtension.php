<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\DependencyInjection;

use InvalidArgumentException;
use Nowo\TranslationYamlToolsBundle\MachineTranslation\LibreTranslateBaseUrlGuard;
use Nowo\TranslationYamlToolsBundle\MachineTranslation\MachineTranslationLocaleMapper;
use Nowo\TranslationYamlToolsBundle\Security\ConfigurableMissingLogUiAccessChecker;
use Nowo\TranslationYamlToolsBundle\Security\MissingLogUiAccessCheckerInterface;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Messenger\MessageBusInterface;

use function array_key_exists;
use function dirname;
use function is_array;
use function is_string;

/**
 * Loads services and parameters for Translation YAML Tools.
 */
final class NowoTranslationYamlToolsExtension extends Extension implements PrependExtensionInterface
{
    /**
     * {@inheritdoc}
     */
    public function prepend(ContainerBuilder $container): void
    {
        if ($container->hasExtension('doctrine') && $this->rawConfigEnablesMissingTranslationLog($container)) {
            $container->prependExtensionConfig('doctrine', [
                'orm' => [
                    'mappings' => [
                        'NowoTranslationYamlToolsMissingLog' => [
                            'is_bundle' => false,
                            'type'      => 'attribute',
                            'dir'       => dirname(__DIR__) . '/Entity',
                            'prefix'    => 'Nowo\\TranslationYamlToolsBundle\\Entity',
                        ],
                    ],
                ],
            ]);
        }

        $this->prependUiKitDefaults($container);
    }

    /**
     * When UiKit is installed, seed nowo_ui_kit from missing_translation_log.web_ui
     * so kit macros resolve the same stack. Does not override keys the host already set.
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
        $webUi  = is_array($config['missing_translation_log']['web_ui'] ?? null)
            ? $config['missing_translation_log']['web_ui']
            : [];
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
        $missingLog          = $config['missing_translation_log'];
        $tablePrefix         = 'nowo_translation_';
        $webUiPathPrefix     = '/_translation_yaml_tools/missing-log';
        $webUiLayoutTemplate = '@NowoTranslationYamlToolsBundle/missing_translation_log/layout.html.twig';

        $missingLogEnabled    = (bool) ($missingLog['enabled'] ?? false);
        $tablePrefix          = (string) ($missingLog['table_prefix'] ?? $tablePrefix);
        $recordCallSite       = (bool) ($missingLog['record_call_site'] ?? true);
        $recordRequestContext = (bool) ($missingLog['record_request_context'] ?? true);
        $asyncPersist         = (bool) ($missingLog['async_persist'] ?? false);
        $asyncPersistStrategy = (string) ($missingLog['async_persist_strategy'] ?? 'messenger');
        $webUi                = is_array($missingLog['web_ui'] ?? null) ? $missingLog['web_ui'] : [];
        $webUiEnabled         = (bool) ($webUi['enabled'] ?? false);
        $webUiPathPrefix      = (string) ($webUi['path_prefix'] ?? $webUiPathPrefix);
        $webUiLayoutTemplate  = (string) ($webUi['layout_template'] ?? $webUiLayoutTemplate);
        $webUiCssFramework    = (string) ($webUi['css_framework'] ?? 'bootstrap5');
        $security             = is_array($webUi['security'] ?? null) ? $webUi['security'] : [];
        /** @var list<string> $accessRoles */
        $accessRoles = [];
        foreach ($security['access_roles'] ?? ['ROLE_ADMIN'] as $role) {
            if (is_string($role) && $role !== '') {
                $accessRoles[] = $role;
            }
        }
        $accessCheckerId           = $security['access_checker'] ?? null;
        $customAccessChecker       = is_string($accessCheckerId) && $accessCheckerId !== '';
        $webUiAllowUnauthenticated = (bool) ($security['allow_unauthenticated'] ?? $webUi['allow_unauthenticated'] ?? false);
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

    private function rawConfigEnablesMissingTranslationLog(ContainerBuilder $container): bool
    {
        foreach ($container->getExtensionConfig('nowo_translation_yaml_tools') as $chunk) {
            if (!is_array($chunk)) {
                continue;
            }
            $missingLog = $chunk['missing_translation_log'] ?? null;
            if ($missingLog === false) {
                continue;
            }
            if (is_array($missingLog) && ($missingLog['enabled'] ?? false) === true) {
                return true;
            }
        }

        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function getAlias(): string
    {
        return 'nowo_translation_yaml_tools';
    }
}
