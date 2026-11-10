<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\DependencyInjection\Compiler;

use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use function array_keys;
use function implode;
use function is_array;
use function is_string;
use function sprintf;
use function str_starts_with;

/**
 * Validates `nowo_translation_yaml_tools.doctrine.entity_manager` (1.17.0, audit LD).
 *
 * The build fails when the configured entity manager does not exist or does not map the bundle entities.
 *
 * DoctrineBundle's `auto_mapping` (the Flex default on the default manager) maps every bundle's `Entity` directory;
 * the bundle namespace is removed from the mapping driver chain of every other manager, so the entities live on the
 * configured manager only and getManagerForClass() returns it.
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
