<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Tests\Unit\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Repository\RepositoryFactory;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectRepository;
use LogicException;
use Nowo\TranslationYamlToolsBundle\Doctrine\TranslationYamlToolsEntityManager;
use Nowo\TranslationYamlToolsBundle\Doctrine\TranslationYamlToolsManagerRegistry;
use Nowo\TranslationYamlToolsBundle\Entity\MissingTranslationLog;
use Nowo\TranslationYamlToolsBundle\Tests\Support\SqliteManagerRegistry;
use Nowo\TranslationYamlToolsBundle\TextOverride\Entity\TranslationOverride;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

use function dirname;

final class TranslationYamlToolsManagerRegistryTest extends TestCase
{
    public function testBundleEntitiesResolveToThePrivateManagerAndTheRestIsDelegated(): void
    {
        $doctrine = new SqliteManagerRegistry(['default' => ['Nowo\\TranslationYamlToolsBundle\\TextOverride\\Entity' => dirname(__DIR__, 3) . '/src/TextOverride/Entity']]);
        $private  = new TranslationYamlToolsEntityManager($doctrine, [TranslationOverride::class]);
        $registry = new TranslationYamlToolsManagerRegistry($doctrine, $private);
        // Plain ORM (no DoctrineBundle container factory): the bundle's service repositories need the registry.
        $host = $doctrine->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $host);
        $host->getConfiguration()->setRepositoryFactory(new class implements RepositoryFactory {
            public function getRepository(EntityManagerInterface $entityManager, string $entityName): EntityRepository
            {
                return new EntityRepository($entityManager, $entityManager->getClassMetadata($entityName));
            }
        });
        // The private manager reads the factory when it is built (the host manager already exists).

        self::assertSame($private, $registry->getManagerForClass(TranslationOverride::class));
        self::assertTrue(TranslationYamlToolsManagerRegistry::isBundleEntity('Proxies\\__CG__\\' . TranslationOverride::class), 'Doctrine proxy class names count.');
        self::assertNull($registry->getManagerForClass(self::class));
        self::assertInstanceOf(EntityRepository::class, $registry->getRepository(TranslationOverride::class));
        $named = $this->createMock(ManagerRegistry::class);
        $named->expects(self::once())->method('getRepository')->with(TranslationOverride::class, 'default')->willReturn($this->createStub(ObjectRepository::class));
        (new TranslationYamlToolsManagerRegistry($named, $private))->getRepository(TranslationOverride::class, 'default');
        self::assertSame('default', $registry->getDefaultConnectionName());
        self::assertSame($doctrine->getConnection(), $registry->getConnection());
        self::assertSame(['default' => 'conn.default'], $registry->getConnectionNames());
        self::assertCount(1, $registry->getConnections());
        self::assertSame('default', $registry->getDefaultManagerName());
        $host = $doctrine->getManager();
        self::assertSame($host, $registry->getManager());
        self::assertSame(['default' => $host], $registry->getManagers());
        self::assertSame(['default' => 'em.default'], $registry->getManagerNames());
        self::assertNotSame($host, $registry->resetManager(), 'Delegated to the application registry (never called by the bundle).');
        self::assertFalse(TranslationYamlToolsManagerRegistry::isBundleEntity('App\\Entity\\Page'));
    }

    public function testPrivateManagerLifecycle(): void
    {
        $doctrine = new SqliteManagerRegistry(['default' => ['Nowo\\TranslationYamlToolsBundle\\TextOverride\\Entity' => dirname(__DIR__, 3) . '/src/TextOverride/Entity']]);
        $private  = new TranslationYamlToolsEntityManager($doctrine, [TranslationOverride::class]);

        self::assertFalse($private->isCreated());
        $private->connectionRolledBack();
        self::assertFalse($private->isStale(), 'Nothing to mark before the manager exists.');
        $private->clear();
        self::assertFalse($private->isCreated(), 'clear() never creates the manager.');

        $live = $private->live();
        self::assertNotSame($doctrine->getManager(), $live);
        self::assertSame($doctrine->getConnection(), $live->getConnection());
        self::assertSame($live, $private->live());
        self::assertSame($live->getConfiguration(), $private->getConfiguration(), 'Decorated calls go to the live manager.');

        $private->connectionRolledBack();
        self::assertTrue($private->isStale());
        self::assertNotSame($live, $private->live(), 'A stale manager without scheduled work is replaced.');
        self::assertFalse($private->isStale());

        $private->reset();
        self::assertFalse($private->isCreated());
        $live = $private->live();
        $live->close();
        self::assertNotSame($live, $private->live(), 'A closed manager is replaced.');
    }

    public function testSourceIsTheFirstMappedEntityAndPendingWorkIsReported(): void
    {
        $doctrine = new SqliteManagerRegistry(['default' => ['Nowo\\TranslationYamlToolsBundle\\TextOverride\\Entity' => dirname(__DIR__, 3) . '/src/TextOverride/Entity']]);
        // Missing log disabled: its entity is not mapped, the override entity names the source manager.
        $private = new TranslationYamlToolsEntityManager($doctrine, [MissingTranslationLog::class, TranslationOverride::class]);
        self::assertSame($doctrine->getManager(), $private->source());
        self::assertTrue(TranslationYamlToolsManagerRegistry::isBundleEntity(MissingTranslationLog::class));

        self::assertFalse($private->hasPendingWork(), 'No manager yet: nothing pending, and none is created.');
        self::assertFalse($private->isCreated());
        self::assertTrue($private->owns($private));
        self::assertFalse($private->owns($doctrine->getManager()));

        $live = $private->live();
        self::assertTrue($private->owns($live));
        self::assertFalse($private->hasPendingWork());
        $private->persist(new TranslationOverride('en', 'messages', 'a.b'));
        self::assertTrue($private->hasPendingWork());
        $live->close();
        self::assertFalse($private->hasPendingWork(), 'A closed manager has nothing it can still write.');
    }

    /** Audit ND-L2: a boundary that discards unflushed bundle work says so. */
    public function testResetWarnsAboutDiscardedPendingWork(): void
    {
        $doctrine = new SqliteManagerRegistry(['default' => ['Nowo\\TranslationYamlToolsBundle\\TextOverride\\Entity' => dirname(__DIR__, 3) . '/src/TextOverride/Entity']]);
        $logger   = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with(self::stringContains('Discarding'), ['count' => 1, 'class' => TranslationYamlToolsEntityManager::class]);
        $private = new TranslationYamlToolsEntityManager($doctrine, [TranslationOverride::class], $logger);

        $private->reset(); // nothing built: no warning
        $private->persist(new TranslationOverride('en', 'messages', 'a.b'));
        self::assertSame(1, $private->pendingCount());
        $private->reset();
        $private->reset(); // dropped: no second warning
        self::assertSame(0, $private->pendingCount());
    }

    public function testUnknownPropertyAndMissingMapping(): void
    {
        $private = new TranslationYamlToolsEntityManager(new SqliteManagerRegistry(['default' => ['App\\Entity' => __DIR__]]), [TranslationOverride::class]);

        try {
            $private->__get('other');
            self::fail('Only $wrapped is magic.');
        } catch (LogicException) {
        }

        $this->expectException(LogicException::class);
        $private->source();
    }
}
