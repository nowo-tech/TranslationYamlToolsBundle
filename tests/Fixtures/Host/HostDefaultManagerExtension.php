<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Tests\Fixtures\Host;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;

/**
 * Another bundle of the host that prepends its configuration on the host's `default` entity manager after this
 * bundle: the default manager then comes first in `doctrine.entity_managers`, so `getManagerForClass()` asks it first.
 */
final class HostDefaultManagerExtension extends Extension implements PrependExtensionInterface
{
    public function prepend(ContainerBuilder $container): void
    {
        $container->prependExtensionConfig('doctrine', ['orm' => ['entity_managers' => ['default' => []]]]);
    }

    /**
     * @param array<array<string, mixed>> $configs
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
    }

    public function getAlias(): string
    {
        return 'translation_yaml_tools_test_host_default_manager';
    }
}
