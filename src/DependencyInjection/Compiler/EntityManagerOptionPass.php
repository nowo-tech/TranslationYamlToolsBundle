<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\DependencyInjection\Compiler;

use Nowo\TranslationYamlToolsBundle\Doctrine\ExcludedEntityNamespaceDriver;
use Nowo\TranslationYamlToolsBundle\EventSubscriber\EntityManagerBoundarySubscriber;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Argument\IteratorArgument;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

use function array_keys;
use function array_merge;
use function implode;
use function is_array;
use function is_string;
use function sprintf;
use function str_starts_with;
use function trigger_deprecation;

/**
 * Validates `nowo_translation_yaml_tools.doctrine.entity_manager` (1.17.0, audit LD).
 *
 * The build fails when the configured entity manager does not exist or does not map the bundle entities.
 *
 * DoctrineBundle's `auto_mapping` (the Flex default on the default manager) maps every bundle's `Entity` directory;
 * the bundle namespaces are removed from the mapping driver chain of every other manager, and an
 * {@see ExcludedEntityNamespaceDriver} is registered for each of them first in those chains instead, so a default
 * driver of the chain cannot claim the entities either (DoctrineBundle 3.4 `auto_discover_entities`). The entities live
 * on the configured manager only and getManagerForClass() returns it. Only the public `addDriver()` of the documented
 * `doctrine.orm.<manager>_metadata_driver` chain is used.
 *
 * Since 1.18.0 (REQ-RUNTIME-002 rule 4) it also hands that manager to {@see EntityManagerBoundarySubscriber}, which
 * clears it at the outermost boundaries, unless it is the host's default manager (the host clears its own managers,
 * rule 7).
 */
final class EntityManagerOptionPass implements CompilerPassInterface
{
    public const PARAMETER = 'nowo_translation_yaml_tools.doctrine.entity_manager';

    /** @var list<string> */
    private const ENTITY_NAMESPACES = ['Nowo\\TranslationYamlToolsBundle\\Entity', 'Nowo\\TranslationYamlToolsBundle\\TextOverride\\Entity'];

    public function process(ContainerBuilder $container): void
    {
        $name = $container->hasParameter(self::PARAMETER) ? $container->getParameter(self::PARAMETER) : null;
        if (!is_string($name) || $name === '') {
            return;
        }

        $managers = $container->hasParameter('doctrine.entity_managers') ? $container->getParameter('doctrine.entity_managers') : [];
        $managers = is_array($managers) ? $managers : [];
        if (!isset($managers[$name])) {
            throw new InvalidConfigurationException(sprintf('nowo_translation_yaml_tools.doctrine.entity_manager is "%s", but no such Doctrine entity manager is configured (known: "%s"). Add it under doctrine.orm.entity_managers or remove the option.', $name, implode('", "', array_keys($managers))));
        }

        $default = $container->hasParameter('doctrine.default_entity_manager') ? $container->getParameter('doctrine.default_entity_manager') : null;
        if ($name === $default) {
            // Audit ND-L4: naming the host's default manager is the 1.17 write path (bundle flushes commit the host's
            // pending changes, a failed one resets that manager), kept as is in 1.x.
            trigger_deprecation('nowo-tech/translation-yaml-tools-bundle', '1.18', 'nowo_translation_yaml_tools.doctrine.entity_manager names the application\'s default entity manager "%s": bundle writes flush its pending changes and a failed one resets it. Remove the option (the bundle then writes through its private entity manager) or name a dedicated entity manager.', $name);
        }
        if ($name !== $default && $container->hasDefinition(EntityManagerBoundarySubscriber::class)) {
            $container->getDefinition(EntityManagerBoundarySubscriber::class)
                ->setArgument('$entityManagers', new IteratorArgument([
                    new Reference(sprintf('doctrine.orm.%s_entity_manager', $name), ContainerInterface::IGNORE_ON_UNINITIALIZED_REFERENCE),
                ]))
                ->setArgument('$registries', new IteratorArgument([
                    new Reference('doctrine', ContainerInterface::IGNORE_ON_UNINITIALIZED_REFERENCE),
                ]));
        }

        foreach (array_keys($managers) as $manager) {
            $chainId = sprintf('doctrine.orm.%s_metadata_driver', $manager);
            if (!$container->hasDefinition($chainId)) {
                continue;
            }

            $chain = $container->getDefinition($chainId);
            $calls = [];
            $maps  = false;
            foreach ($chain->getMethodCalls() as $call) {
                $namespace = $call[0] === 'addDriver' ? ($call[1][1] ?? null) : null;
                if ($manager !== $name && $this->isBundleNamespace($namespace, false)) {
                    continue;
                }

                $maps    = $maps || ($manager === $name && $this->isBundleNamespace($namespace, true));
                $calls[] = $call;
            }
            if ($manager !== $name) {
                // First in the chain: a chain asks its namespace drivers in order, then its default driver.
                $excluded = [];
                foreach (self::ENTITY_NAMESPACES as $bundleNamespace) {
                    $excluded[] = ['addDriver', [new Definition(ExcludedEntityNamespaceDriver::class), $bundleNamespace]];
                }
                $calls = array_merge($excluded, $calls);
            }
            $chain->setMethodCalls($calls);

            if ($manager === $name && !$maps) {
                throw new InvalidConfigurationException(sprintf('The Doctrine entity manager "%s" (nowo_translation_yaml_tools.doctrine.entity_manager) does not map %s.', $name, implode(', ', self::ENTITY_NAMESPACES)));
            }
        }
    }

    /**
     * @param bool $orParent also accept a parent namespace of the bundle entities (a host mapping of a wider namespace)
     */
    private function isBundleNamespace(mixed $namespace, bool $orParent): bool
    {
        if (!is_string($namespace)) {
            return false;
        }
        foreach (self::ENTITY_NAMESPACES as $bundleNamespace) {
            if (str_starts_with($namespace, $bundleNamespace) || ($orParent && str_starts_with($bundleNamespace, $namespace . '\\'))) {
                return true;
            }
        }

        return false;
    }
}
