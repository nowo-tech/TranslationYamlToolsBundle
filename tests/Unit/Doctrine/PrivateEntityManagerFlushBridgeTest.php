<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Tests\Unit\Doctrine;

use Doctrine\ORM\Decorator\EntityManagerDecorator;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\TranslationYamlToolsBundle\Doctrine\PrivateEntityManagerFlushBridge;
use Nowo\TranslationYamlToolsBundle\Doctrine\TranslationYamlToolsEntityManager;
use Nowo\TranslationYamlToolsBundle\Tests\Support\SqliteManagerRegistry;
use Nowo\TranslationYamlToolsBundle\TextOverride\Entity\TranslationOverride;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Throwable;

use function dirname;

final class PrivateEntityManagerFlushBridgeTest extends TestCase
{
    #[IgnoreDeprecations]
    public function testFlushesStagedRowsOnlyAfterTheSourceManagerFlushed(): void
    {
        $mapping  = ['Nowo\\TranslationYamlToolsBundle\\TextOverride\\Entity' => dirname(__DIR__, 3) . '/src/TextOverride/Entity'];
        $doctrine = new SqliteManagerRegistry(['default' => $mapping, 'other' => $mapping]);
        $private  = new TranslationYamlToolsEntityManager($doctrine, [TranslationOverride::class]);
        $bridge   = new PrivateEntityManagerFlushBridge([$private]);
        $source   = $private->source();

        // Nothing built yet: nothing to do (and the manager is not created).
        (new PrivateEntityManagerFlushBridge())->postFlush(new PostFlushEventArgs($source));
        $bridge->postFlush(new PostFlushEventArgs($source));
        self::assertFalse($private->isCreated());

        $private->persist(new TranslationOverride('en', 'messages', 'site.a'));
        // Another manager's flush or the private manager's own flush never writes the staged row.
        $other = $doctrine->getManager('other');
        self::assertInstanceOf(EntityManagerInterface::class, $other);
        $bridge->postFlush(new PostFlushEventArgs($other));
        $bridge->postFlush(new PostFlushEventArgs($private->live()));
        self::assertTrue($private->hasPendingWork());

        (new SchemaTool($private->live()))->createSchema([$private->getClassMetadata(TranslationOverride::class)]);
        $bridge->postFlush(new PostFlushEventArgs($source));
        self::assertFalse($private->hasPendingWork(), 'Written after the source manager flushed.');
        self::assertSame(1, (int) $source->getConnection()->fetchOne('SELECT COUNT(*) FROM TranslationOverride'));
    }

    /** Audit ND-L1: the registry may hand out a decorator of the manager whose flush is reported. */
    #[IgnoreDeprecations]
    public function testDecoratedSourceManagerIsRecognisedByItsUnitOfWork(): void
    {
        $mapping  = ['Nowo\\TranslationYamlToolsBundle\\TextOverride\\Entity' => dirname(__DIR__, 3) . '/src/TextOverride/Entity'];
        $doctrine = new SqliteManagerRegistry(['default' => $mapping]);
        $inner    = $doctrine->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $inner);
        $decorated = new class($inner) extends EntityManagerDecorator {
        };
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($decorated);
        $private = new TranslationYamlToolsEntityManager($registry, [TranslationOverride::class]);
        (new SchemaTool($private->live()))->createSchema([$private->getClassMetadata(TranslationOverride::class)]);

        $private->persist(new TranslationOverride('en', 'messages', 'site.a'));
        (new PrivateEntityManagerFlushBridge([$private]))->postFlush(new PostFlushEventArgs($inner));

        self::assertFalse($private->hasPendingWork());
        self::assertSame(1, (int) $inner->getConnection()->fetchOne('SELECT COUNT(*) FROM TranslationOverride'));
    }

    /** Audit ND-M1: a failing bridge flush is logged with its row count and never rethrown into the host's flush. */
    #[IgnoreDeprecations]
    public function testFailingBridgeFlushIsLoggedAndNotRethrown(): void
    {
        $mapping  = ['Nowo\\TranslationYamlToolsBundle\\TextOverride\\Entity' => dirname(__DIR__, 3) . '/src/TextOverride/Entity'];
        $doctrine = new SqliteManagerRegistry(['default' => $mapping]);
        $private  = new TranslationYamlToolsEntityManager($doctrine, [TranslationOverride::class]);
        $source   = $private->source();
        // No schema: the flush of the staged row fails.
        $private->persist(new TranslationOverride('en', 'messages', 'site.a'));
        $private->persist(new TranslationOverride('es', 'messages', 'site.a'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with(
            self::stringContains('could not be written'),
            self::callback(static fn (array $context): bool => $context['count'] === 2 && $context['exception'] instanceof Throwable),
        );
        (new PrivateEntityManagerFlushBridge([$private], $logger))->postFlush(new PostFlushEventArgs($source));

        self::assertFalse($private->hasPendingWork(), 'The closed private manager has nothing left to write.');
    }
}
