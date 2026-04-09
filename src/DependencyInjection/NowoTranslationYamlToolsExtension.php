<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\DependencyInjection;

use Nowo\TranslationYamlToolsBundle\MachineTranslation\MachineTranslationLocaleMapper;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

/**
 * Loads services and parameters for Translation YAML Tools.
 */
final class NowoTranslationYamlToolsExtension extends Extension
{
    /**
     * {@inheritdoc}
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config        = $this->processConfiguration($configuration, $configs);

        $container->setParameter('nowo_translation_yaml_tools.default_locale', $config['default_locale']);
        $container->setParameter('nowo_translation_yaml_tools.yaml_tree_indent', $config['yaml_tree_indent']);
        $container->setParameter('nowo_translation_yaml_tools.machine_translator', $config['machine_translator']);
        $container->setParameter('nowo_translation_yaml_tools.deepl_endpoint', $config['deepl_endpoint']);
        $container->setParameter('nowo_translation_yaml_tools.libretranslate_base_url', $config['libretranslate_base_url']);
        $container->setParameter('nowo_translation_yaml_tools.libretranslate_api_key', $config['libretranslate_api_key']);

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

        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.yaml');
    }

    /**
     * {@inheritdoc}
     */
    public function getAlias(): string
    {
        return 'nowo_translation_yaml_tools';
    }
}
