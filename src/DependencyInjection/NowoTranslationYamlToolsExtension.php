<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\DependencyInjection;

use Nowo\TranslationYamlToolsBundle\MachineTranslation\LibreTranslateBaseUrlGuard;
use Nowo\TranslationYamlToolsBundle\MachineTranslation\MachineTranslationLocaleMapper;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

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
        } catch (\InvalidArgumentException $e) {
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
        $missingLog           = $config['missing_translation_log'];
        $tablePrefix          = 'nowo_translation_';
        $recordCallSite       = true;
        $recordRequestContext = true;
        $asyncPersist         = false;
        $asyncPersistStrategy = 'messenger';
        $webUiEnabled         = false;
        $webUiPathPrefix      = '/_translation_yaml_tools/missing-log';
        $webUiLayoutTemplate  = '@NowoTranslationYamlToolsBundle/missing_translation_log/layout.html.twig';

        $missingLogEnabled    = (bool) ($missingLog['enabled'] ?? false);
        $tablePrefix          = (string) ($missingLog['table_prefix'] ?? $tablePrefix);
        $recordCallSite       = (bool) ($missingLog['record_call_site'] ?? true);
        $recordRequestContext = (bool) ($missingLog['record_request_context'] ?? true);
        $asyncPersist         = (bool) ($missingLog['async_persist'] ?? false);
        $asyncPersistStrategy = (string) ($missingLog['async_persist_strategy'] ?? 'messenger');
        $webUi                   = is_array($missingLog['web_ui'] ?? null) ? $missingLog['web_ui'] : [];
        $webUiEnabled            = (bool) ($webUi['enabled'] ?? false);
        $webUiPathPrefix         = (string) ($webUi['path_prefix'] ?? $webUiPathPrefix);
        $webUiLayoutTemplate     = (string) ($webUi['layout_template'] ?? $webUiLayoutTemplate);
        $webUiAllowUnauthenticated = (bool) ($webUi['allow_unauthenticated'] ?? false);
        $webUiRequiredRole       = array_key_exists('required_role', $webUi)
            ? (is_string($webUi['required_role']) && $webUi['required_role'] !== '' ? $webUi['required_role'] : null)
            : 'ROLE_ADMIN';

        $container->setParameter('nowo_translation_yaml_tools.missing_translation_log.enabled', $missingLogEnabled);
        $container->setParameter('nowo_translation_yaml_tools.missing_translation_log.table_prefix', $tablePrefix);
        $container->setParameter('nowo_translation_yaml_tools.missing_translation_log.record_call_site', $recordCallSite);
        $container->setParameter('nowo_translation_yaml_tools.missing_translation_log.record_request_context', $recordRequestContext);
        $container->setParameter('nowo_translation_yaml_tools.missing_translation_log.async_persist', $asyncPersist);
        $container->setParameter('nowo_translation_yaml_tools.missing_translation_log.async_persist_strategy', $asyncPersistStrategy);
        $container->setParameter('nowo_translation_yaml_tools.missing_translation_log.web_ui.enabled', $webUiEnabled);
        $container->setParameter('nowo_translation_yaml_tools.missing_translation_log.web_ui.path_prefix', $webUiPathPrefix);
        $container->setParameter('nowo_translation_yaml_tools.missing_translation_log.web_ui.layout_template', $webUiLayoutTemplate);
        $container->setParameter('nowo_translation_yaml_tools.missing_translation_log.web_ui.required_role', $webUiRequiredRole);
        $container->setParameter('nowo_translation_yaml_tools.missing_translation_log.web_ui.allow_unauthenticated', $webUiAllowUnauthenticated);

        // SecurityBundle presence + MissingLogUiAccessSubscriber: MissingLogWebUiSecurityPass
        // (Extension::load runs in an isolated container where other bundles are invisible).

        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.yaml');

        if ($missingLogEnabled) {
            $loader->load('services_missing_translation.yaml');
            if ($asyncPersist && $asyncPersistStrategy === 'messenger' && interface_exists(\Symfony\Component\Messenger\MessageBusInterface::class)) {
                $loader->load('services_missing_translation_messenger.yaml');
            }
            if ($asyncPersist && $asyncPersistStrategy === 'event_dispatcher') {
                $loader->load('services_missing_translation_event_dispatcher.yaml');
            }
            if ($webUiEnabled) {
                $loader->load('services_missing_translation_web.yaml');
            }
        }
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
