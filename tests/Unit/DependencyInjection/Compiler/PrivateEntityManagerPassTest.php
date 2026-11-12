<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Tests\Unit\DependencyInjection\Compiler;

use Nowo\TranslationYamlToolsBundle\DependencyInjection\Compiler\PrivateEntityManagerPass;
use Nowo\TranslationYamlToolsBundle\Doctrine\PrivateEntityManagerMiddleware;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

final class PrivateEntityManagerPassTest extends TestCase
{
    public function testAttachesTheMiddlewareToTheSourceManagerConnectionOnly(): void
    {
        $container = $this->container();
        $container->setParameter('doctrine.default_entity_manager', 'main');
        $container->setDefinition('doctrine.orm.main_entity_manager', new Definition('EM', [new Reference('doctrine.dbal.app_connection')]));

        (new PrivateEntityManagerPass())->process($container);

        self::assertSame([['connection' => 'app']], $container->getDefinition(PrivateEntityManagerMiddleware::class)->getTag('doctrine.middleware'));
    }

    public function testFallsBackToTheDefaultConnection(): void
    {
        $container = $this->container();
        $container->setParameter('doctrine.default_connection', 'primary');
        (new PrivateEntityManagerPass())->process($container);
        self::assertSame([['connection' => 'primary']], $container->getDefinition(PrivateEntityManagerMiddleware::class)->getTag('doctrine.middleware'));

        $container = $this->container();
        (new PrivateEntityManagerPass())->process($container);
        self::assertSame([['connection' => 'default']], $container->getDefinition(PrivateEntityManagerMiddleware::class)->getTag('doctrine.middleware'));
    }

    /** Audit ND-L3: the connection of the manager whose driver chain maps the bundle entities, not the default's. */
    public function testAttachesTheMiddlewareToTheConnectionOfTheManagerThatMapsTheEntities(): void
    {
        $container = $this->container();
        $container->setParameter('doctrine.default_entity_manager', 'default');
        $container->setParameter('doctrine.entity_managers', ['default' => 'doctrine.orm.default_entity_manager', 'missing' => 'x', 'texts' => 'doctrine.orm.texts_entity_manager']);
        $container->setDefinition('doctrine.orm.default_entity_manager', new Definition('EM', [new Reference('doctrine.dbal.default_connection')]));
        $container->setDefinition('doctrine.orm.texts_entity_manager', new Definition('EM', [new Reference('doctrine.dbal.texts_connection')]));
        $container->register('doctrine.orm.default_metadata_driver')
            ->addMethodCall('setDefaultDriver', [new Reference('driver')])
            ->addMethodCall('addDriver', [new Reference('driver'), 'App\\Entity']);
        $container->register('doctrine.orm.texts_metadata_driver')
            ->addMethodCall('addDriver', [new Reference('driver'), 'Nowo\\TranslationYamlToolsBundle\\TextOverride\\Entity']);

        (new PrivateEntityManagerPass())->process($container);
        self::assertSame([['connection' => 'texts']], $container->getDefinition(PrivateEntityManagerMiddleware::class)->getTag('doctrine.middleware'));

        // A parent namespace mapping counts too.
        $container->getDefinition('doctrine.orm.texts_metadata_driver')->setMethodCalls([['addDriver', [new Reference('driver'), 'Nowo\\']]]);
        (new PrivateEntityManagerPass())->process($container);
        self::assertSame([['connection' => 'texts']], $container->getDefinition(PrivateEntityManagerMiddleware::class)->getTag('doctrine.middleware'));
    }

    public function testDoesNothingWithoutThePrivateManager(): void
    {
        $container = new ContainerBuilder();
        (new PrivateEntityManagerPass())->process($container);
        self::assertFalse($container->hasDefinition(PrivateEntityManagerMiddleware::class));
    }

    public function testSupportsRollbackMiddlewareWithDbal4(): void
    {
        if (!PrivateEntityManagerPass::supportsRollbackMiddleware()) {
            self::markTestSkipped('The rollback middleware requires doctrine/dbal 4.');
        }

        self::assertTrue(PrivateEntityManagerPass::supportsRollbackMiddleware());
    }

    private function container(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->register(PrivateEntityManagerMiddleware::class)->addTag('doctrine.middleware');

        return $container;
    }
}
