<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Tests\Unit\DependencyInjection\Compiler;

use Nowo\TranslationYamlToolsBundle\DependencyInjection\Compiler\EntityManagerOptionPass;
use Nowo\TranslationYamlToolsBundle\Doctrine\ExcludedEntityNamespaceDriver;
use Nowo\TranslationYamlToolsBundle\EventSubscriber\EntityManagerBoundarySubscriber;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Argument\IteratorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

use function array_map;
use function restore_error_handler;
use function set_error_handler;

use const E_USER_DEPRECATED;

/**
 * Audit LD: the named entity manager must exist and map the bundle entities.
 */
final class EntityManagerOptionPassTest extends TestCase
{
    private const ENTITIES = 'Nowo\\TranslationYamlToolsBundle\\Entity';

    public function testNoOptionIsANoop(): void
    {
        $container = $this->container(null, ['default' => [self::ENTITIES]]);

        (new EntityManagerOptionPass())->process($container);

        self::assertCount(1, $container->getDefinition('doctrine.orm.default_metadata_driver')->getMethodCalls());
    }

    public function testUnknownManagerFailsTheBuild(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('no such Doctrine entity manager');

        (new EntityManagerOptionPass())->process($this->container('missing', ['default' => [self::ENTITIES]]));
    }

    public function testManagerThatDoesNotMapTheEntitiesFailsTheBuild(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('does not map');

        (new EntityManagerOptionPass())->process($this->container('bundle', ['default' => [self::ENTITIES], 'bundle' => ['App\\Entity']]));
    }

    public function testParentNamespaceMappingCounts(): void
    {
        $container = $this->container('bundle', ['default' => ['App\\Entity'], 'bundle' => ['Nowo']]);

        (new EntityManagerOptionPass())->process($container);

        self::assertCount(1, $container->getDefinition('doctrine.orm.bundle_metadata_driver')->getMethodCalls());
    }

    public function testNamedManagerKeepsTheEntities(): void
    {
        $container = $this->container('bundle', ['default' => ['App\\Entity', self::ENTITIES], 'bundle' => [self::ENTITIES], 'other' => []]);
        $container->removeDefinition('doctrine.orm.other_metadata_driver');

        (new EntityManagerOptionPass())->process($container);

        self::assertCount(1, $container->getDefinition('doctrine.orm.bundle_metadata_driver')->getMethodCalls());
        // App\Entity plus a driver that maps nothing for each bundle entity namespace.
        self::assertCount(3, $container->getDefinition('doctrine.orm.default_metadata_driver')->getMethodCalls());
    }

    /**
     * DoctrineBundle 3.4 `auto_discover_entities` gives a manager's chain a default driver that claims every entity
     * class: every other manager gets a driver that maps nothing for each bundle entity namespace, first in its chain
     * (before the host's drivers and the default driver); the configured manager keeps its own. Idempotent.
     */
    public function testOtherManagersGetAnExcludedDriverFirstForEachBundleNamespace(): void
    {
        $textOverrides = 'Nowo\\TranslationYamlToolsBundle\\TextOverride\\Entity';
        $container     = $this->container('bundle', ['default' => ['App\\Entity', self::ENTITIES], 'bundle' => [self::ENTITIES, $textOverrides], 'reports' => []]);
        $container->getDefinition('doctrine.orm.default_metadata_driver')->addMethodCall('setDefaultDriver', [new Reference('auto_discover')]);

        for ($run = 0; $run < 2; ++$run) {
            (new EntityManagerOptionPass())->process($container);

            $calls = static fn (string $manager): array => array_map(
                static fn (array $call): array => [$call[0], $call[1][1] ?? null, $call[1][0] instanceof Definition ? $call[1][0]->getClass() : (string) $call[1][0]],
                $container->getDefinition('doctrine.orm.' . $manager . '_metadata_driver')->getMethodCalls(),
            );
            self::assertSame([
                ['addDriver', self::ENTITIES, ExcludedEntityNamespaceDriver::class],
                ['addDriver', $textOverrides, ExcludedEntityNamespaceDriver::class],
                ['addDriver', 'App\\Entity', 'driver'],
                ['setDefaultDriver', null, 'auto_discover'],
            ], $calls('default'), 'run ' . $run);
            self::assertSame([
                ['addDriver', self::ENTITIES, ExcludedEntityNamespaceDriver::class],
                ['addDriver', $textOverrides, ExcludedEntityNamespaceDriver::class],
            ], $calls('reports'), 'run ' . $run);
            self::assertSame([['addDriver', self::ENTITIES, 'driver'], ['addDriver', $textOverrides, 'driver']], $calls('bundle'), 'run ' . $run);
        }
    }

    public function testOtherDriverCallsAreKept(): void
    {
        $container = $this->container('bundle', ['default' => [], 'bundle' => []]);
        $container->getDefinition('doctrine.orm.bundle_metadata_driver')
            ->addMethodCall('setDefaultDriver', [new Reference('driver')])
            ->addMethodCall('addDriver', [new Reference('driver'), self::ENTITIES]);

        (new EntityManagerOptionPass())->process($container);

        self::assertCount(2, $container->getDefinition('doctrine.orm.bundle_metadata_driver')->getMethodCalls());
        self::assertCount(2, $container->getDefinition('doctrine.orm.default_metadata_driver')->getMethodCalls(), 'the excluded drivers');
    }

    /**
     * 1.18.0: the dedicated manager is handed to the boundary subscriber (cleared at the outermost boundaries) as
     * IGNORE_ON_UNINITIALIZED references; nothing when the option names the host's default manager.
     */
    #[IgnoreDeprecations]
    public function testDedicatedManagerIsWiredIntoTheBoundarySubscriberUnlessItIsTheDefault(): void
    {
        $container = $this->container('bundle', ['default' => [], 'bundle' => [self::ENTITIES]]);
        $container->setParameter('doctrine.default_entity_manager', 'default');
        $container->register(EntityManagerBoundarySubscriber::class)
            ->setArgument('$entityManagers', new IteratorArgument([]))
            ->setArgument('$registries', new IteratorArgument([]));

        (new EntityManagerOptionPass())->process($container);

        $subscriber = $container->getDefinition(EntityManagerBoundarySubscriber::class);
        $managers   = $subscriber->getArgument('$entityManagers');
        self::assertInstanceOf(IteratorArgument::class, $managers);
        $reference = $managers->getValues()[0];
        self::assertInstanceOf(Reference::class, $reference);
        self::assertSame('doctrine.orm.bundle_entity_manager', (string) $reference);
        self::assertSame(ContainerInterface::IGNORE_ON_UNINITIALIZED_REFERENCE, $reference->getInvalidBehavior());
        $registries = $subscriber->getArgument('$registries');
        self::assertInstanceOf(IteratorArgument::class, $registries);
        self::assertSame('doctrine', (string) $registries->getValues()[0]);

        // The option names the host's default manager: the host clears it (REQ-RUNTIME-002 rule 7), not the bundle.
        $container = $this->container('default', ['default' => [self::ENTITIES]]);
        $container->setParameter('doctrine.default_entity_manager', 'default');
        $container->register(EntityManagerBoundarySubscriber::class)->setArgument('$entityManagers', new IteratorArgument([]));
        $deprecations = [];
        // Test-only handler, restored in finally.
        // @phpstan-ignore frankenphp.worker.noSetErrorExceptionHandler
        set_error_handler(static function (int $level, string $message) use (&$deprecations): bool {
            $deprecations[] = $message;

            return true;
        }, E_USER_DEPRECATED);
        try {
            (new EntityManagerOptionPass())->process($container);
        } finally {
            restore_error_handler();
        }
        self::assertCount(1, $deprecations, 'Audit ND-L4: naming the host default manager is deprecated at build time.');
        self::assertStringContainsString('names the application\'s default entity manager "default"', $deprecations[0]);
        $managers = $container->getDefinition(EntityManagerBoundarySubscriber::class)->getArgument('$entityManagers');
        self::assertInstanceOf(IteratorArgument::class, $managers);
        self::assertSame([], $managers->getValues());
    }

    /**
     * @param array<string, list<string>> $managers manager => mapped namespaces
     */
    private function container(?string $option, array $managers): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter(EntityManagerOptionPass::PARAMETER, $option);
        $names = [];
        foreach ($managers as $manager => $namespaces) {
            $names[$manager] = 'doctrine.orm.' . $manager . '_entity_manager';
            $chain           = $container->register('doctrine.orm.' . $manager . '_metadata_driver');
            foreach ($namespaces as $namespace) {
                $chain->addMethodCall('addDriver', [new Reference('driver'), $namespace]);
            }
        }
        $container->setParameter('doctrine.entity_managers', $names);

        return $container;
    }
}
