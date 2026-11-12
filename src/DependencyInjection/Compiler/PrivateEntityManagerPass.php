<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\DependencyInjection\Compiler;

use Doctrine\DBAL\Driver\Connection;
use Nowo\TranslationYamlToolsBundle\Doctrine\PrivateEntityManagerMiddleware;
use Nowo\TranslationYamlToolsBundle\Doctrine\TranslationYamlToolsManagerRegistry;
use ReflectionMethod;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

use function array_keys;
use function interface_exists;
use function is_array;
use function is_string;
use function preg_match;
use function rtrim;
use function sprintf;
use function str_starts_with;

/**
 * Attaches {@see PrivateEntityManagerMiddleware} (1.18.0) only to the connection of the entity manager that maps the
 * bundle entities (the source of the private manager), found through the metadata driver chains, else the default
 * manager's: the host's other connections are never wrapped. Runs before
 * DoctrineBundle's MiddlewaresPass.
 *
 * @internal
 */
final class PrivateEntityManagerPass implements CompilerPassInterface
{
    /**
     * The rollback middleware is written against the doctrine/dbal 4 driver API; with DBAL 3 the private manager runs
     * without it (no replacement after a rollback, see docs/CONFIGURATION.md).
     */
    public static function supportsRollbackMiddleware(): bool
    {
        if (!interface_exists(Connection::class)) {
            return false; // @codeCoverageIgnore
        }

        return (string) (new ReflectionMethod(Connection::class, 'rollBack'))->getReturnType() === 'void';
    }

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(PrivateEntityManagerMiddleware::class)) {
            return;
        }

        $manager = $this->mappingManager($container);
        if ($manager === null) {
            $manager = $container->hasParameter('doctrine.default_entity_manager') ? $container->getParameter('doctrine.default_entity_manager') : null;
        }
        $id = sprintf('doctrine.orm.%s_entity_manager', is_string($manager) && $manager !== '' ? $manager : 'default');
        // DoctrineBundle passes the connection as the manager's first argument.
        $argument   = $container->hasDefinition($id) ? ($container->getDefinition($id)->getArguments()[0] ?? null) : null;
        $connection = $argument instanceof Reference && preg_match('/^doctrine\.dbal\.(.+)_connection$/D', (string) $argument, $match) === 1 ? $match[1] : null;
        if ($connection === null) {
            $default    = $container->hasParameter('doctrine.default_connection') ? $container->getParameter('doctrine.default_connection') : null;
            $connection = is_string($default) && $default !== '' ? $default : 'default';
        }

        $container->getDefinition(PrivateEntityManagerMiddleware::class)
            ->clearTag('doctrine.middleware')
            ->addTag('doctrine.middleware', ['connection' => $connection]);
    }

    /**
     * The entity manager whose metadata driver chain maps the bundle entities (audit ND-L3): the private manager is
     * built on that manager's connection, which may not be the default one (a host that maps the bundle namespace on
     * another manager without `doctrine.entity_manager`). Null when no chain maps them (the default manager is used).
     */
    private function mappingManager(ContainerBuilder $container): ?string
    {
        $managers = $container->hasParameter('doctrine.entity_managers') ? $container->getParameter('doctrine.entity_managers') : [];
        foreach (is_array($managers) ? array_keys($managers) : [] as $name) {
            $chainId = sprintf('doctrine.orm.%s_metadata_driver', $name);
            if (!$container->hasDefinition($chainId)) {
                continue;
            }
            foreach ($container->getDefinition($chainId)->getMethodCalls() as $call) {
                $namespace = $call[0] === 'addDriver' ? ($call[1][1] ?? null) : null;
                if (!is_string($namespace) || $namespace === '') {
                    continue;
                }
                foreach (TranslationYamlToolsManagerRegistry::ENTITY_NAMESPACES as $bundleNamespace) {
                    // The bundle namespace itself, a sub-namespace of it, or a parent namespace that contains it.
                    if (str_starts_with($namespace . '\\', $bundleNamespace) || str_starts_with($bundleNamespace, rtrim($namespace, '\\') . '\\')) {
                        return (string) $name;
                    }
                }
            }
        }

        return null;
    }
}
