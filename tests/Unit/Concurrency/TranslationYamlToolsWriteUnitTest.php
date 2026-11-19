<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Tests\Unit\Concurrency;

use Doctrine\Common\EventManager;
use Doctrine\DBAL\Driver\AbstractException;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use DomainException;
use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\TranslationYamlToolsBundle\Concurrency\TranslationYamlToolsWriteUnit;
use Nowo\TranslationYamlToolsBundle\Doctrine\TranslationYamlToolsEntityManager;
use Nowo\TranslationYamlToolsBundle\Tests\Support\SqliteManagerRegistry;
use Nowo\TranslationYamlToolsBundle\TextOverride\Doctrine\TranslationOverrideMetadataListener;
use Nowo\TranslationYamlToolsBundle\TextOverride\Entity\TranslationOverride;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

use function dirname;
use function sys_get_temp_dir;

use const PHP_VERSION_ID;

/**
 * The bundle's write unit (1.19, REQ-RUNTIME-003 rules 1–3) on real in-memory SQLite managers: one transaction, the
 * bundle's own managers cleaned up after a storage failure, the application's open manager never cleared or reset.
 */
#[CoversClass(TranslationYamlToolsWriteUnit::class)]
final class TranslationYamlToolsWriteUnitTest extends TestCase
{
    private const BUNDLE = ['Nowo\\TranslationYamlToolsBundle\\TextOverride\\Entity' => __DIR__ . '/../../../src/TextOverride/Entity'];

    public function testDbalUnitCommitsAndFlushesNoManager(): void
    {
        $registry = $this->registry(['default' => self::BUNDLE]);
        $manager  = $this->schema($registry, 'default');
        $manager->persist(new TranslationOverride('en', 'messages', 'host.pending'));

        $result = TranslationYamlToolsWriteUnit::run($manager, $registry, static fn (): int|string => $manager->getConnection()->executeStatement(
            "INSERT INTO nowo_translation_override (locale, domain, message_key, value, created_at, updated_at) VALUES ('en', 'messages', 'a', 'A', '2026-01-01 00:00:00', '2026-01-01 00:00:00')",
        ), false);

        self::assertSame(1, (int) $result);
        self::assertSame(1, $this->rowCount($manager), 'The pending entity of the manager is not flushed by a DBAL unit.');
        self::assertSame(0, $manager->getConnection()->getTransactionNestingLevel());
    }

    public function testPrivateManagerUnitFlushesAndIsReplacedAfterAStorageFailure(): void
    {
        $registry = $this->registry(['default' => self::BUNDLE]);
        $host     = $this->schema($registry, 'default');
        $private  = new TranslationYamlToolsEntityManager($registry, [TranslationOverride::class]);

        TranslationYamlToolsWriteUnit::run($private, $registry, static function () use ($private): void {
            $private->persist((new TranslationOverride('en', 'messages', 'a'))->setValue('A'));
        });
        self::assertSame(1, $this->rowCount($host));

        try {
            TranslationYamlToolsWriteUnit::run($private, $registry, static function () use ($private): void {
                $private->persist((new TranslationOverride('en', 'messages', 'a'))->setValue('again'));
            });
            self::fail('A duplicate key must fail.');
        } catch (Throwable $failure) {
            self::assertTrue(TranslationYamlToolsWriteUnit::isUniqueViolation($failure));
            self::assertNotInstanceOf(TransientStorageFailure::class, $failure, 'A unique violation is never a conflict.');
        }

        self::assertTrue($private->isOpen(), 'The private manager is replaced on its next use.');
        self::assertSame(1, $this->rowCount($host));
        self::assertSame($host, $registry->getManager('default'), 'The application manager is never reset.');
        self::assertTrue($host->isOpen());
    }

    public function testDedicatedManagerIsResetByNameAndTheHostManagerIsUntouched(): void
    {
        $registry = $this->registry(['default' => ['App\\Entity' => dirname(__DIR__, 2)], 'texts' => self::BUNDLE]);
        $host     = $this->manager($registry, 'default');
        $texts    = $this->schema($registry, 'texts');

        try {
            TranslationYamlToolsWriteUnit::run($texts, $registry, static function () use ($texts): void {
                $texts->persist(new TranslationOverride('en', 'messages', 'dup'));
                $texts->persist(new TranslationOverride('en', 'messages', 'dup'));
            }, true, 'texts');
            self::fail('A duplicate key must fail.');
        } catch (Throwable $failure) {
            self::assertTrue(TranslationYamlToolsWriteUnit::isUniqueViolation($failure));
        }

        self::assertNotSame($texts, $registry->getManager('texts'), 'The dedicated manager was reset through the registry.');
        self::assertTrue($this->manager($registry, 'texts')->isOpen());
        self::assertSame($host, $registry->getManager('default'));
        self::assertSame(0, $this->rowCount($this->manager($registry, 'texts')));
    }

    public function testHostManagerUnitFlushesItAndReplacesItOnlyWhenClosed(): void
    {
        $registry = $this->registry(['default' => self::BUNDLE]);
        $host     = $this->schema($registry, 'default');

        TranslationYamlToolsWriteUnit::run($host, $registry, static function () use ($host): void {
            $host->persist(new TranslationOverride('en', 'messages', 'a'));
        });
        self::assertSame(1, $this->rowCount($host));

        // A domain failure leaves the open application manager alone (rule 2): its pending entity stays managed.
        $pending = new TranslationOverride('en', 'messages', 'host.pending');
        $host->persist($pending);
        $failure = $this->failureOf(static fn (): mixed => TranslationYamlToolsWriteUnit::run($host, $registry, static function (): never {
            throw new DomainException('refused');
        }));
        self::assertInstanceOf(DomainException::class, $failure, 'The domain failure is rethrown.');
        self::assertTrue($host->contains($pending));
        self::assertSame($host, $registry->getManager('default'));

        // A failed flush closes it: then, and only then, it is replaced through the registry.
        $host->persist(new TranslationOverride('en', 'messages', 'a'));
        try {
            TranslationYamlToolsWriteUnit::run($host, $registry, static fn (): null => null);
            self::fail('A duplicate key must fail.');
        } catch (Throwable $failure) {
            self::assertTrue(TranslationYamlToolsWriteUnit::isUniqueViolation($failure));
        }
        self::assertFalse($host->isOpen());
        self::assertNotSame($host, $registry->getManager('default'));
        self::assertTrue($this->manager($registry, 'default')->isOpen());
        self::assertSame(1, $this->rowCount($this->manager($registry, 'default')), 'The failed unit wrote nothing.');
    }

    public function testManagerOutsideTheRegistryIsNeverReset(): void
    {
        $registry = $this->registry(['default' => self::BUNDLE]);
        $config   = ORMSetup::createAttributeMetadataConfig([__DIR__ . '/../../../src/TextOverride/Entity'], true);
        if (PHP_VERSION_ID >= 80400) {
            $config->enableNativeLazyObjects(true);
        } else {
            $config->setProxyDir(sys_get_temp_dir() . '/nowo-registry-tests/proxies');
            $config->setProxyNamespace('NowoRegistryTestProxies');
        }
        $events = new EventManager();
        $events->addEventListener(Events::loadClassMetadata, new TranslationOverrideMetadataListener('nowo_translation_'));
        $loose = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]), $config, $events);
        (new SchemaTool($loose))->createSchema([$loose->getClassMetadata(TranslationOverride::class)]);
        $default = $registry->getManager('default');
        $loose->persist(new TranslationOverride('en', 'messages', 'dup'));
        $loose->persist(new TranslationOverride('en', 'messages', 'dup'));

        try {
            TranslationYamlToolsWriteUnit::run($loose, $registry, static fn (): null => null);
            self::fail('A duplicate key must fail.');
        } catch (Throwable $failure) {
            self::assertTrue(TranslationYamlToolsWriteUnit::isUniqueViolation($failure));
        }

        self::assertFalse($loose->isOpen());
        self::assertSame($default, $registry->getManager('default'));
    }

    public function testOnlyACleanBundleOwnedManagerMayRunAUnitAgain(): void
    {
        $registry = $this->registry(['default' => self::BUNDLE, 'texts' => self::BUNDLE]);
        $host     = $this->schema($registry, 'default');
        $texts    = $this->schema($registry, 'texts');
        $private  = new TranslationYamlToolsEntityManager($registry, [TranslationOverride::class]);

        self::assertTrue(TranslationYamlToolsWriteUnit::isCleanBundleManager($private, $registry, null));
        self::assertTrue(TranslationYamlToolsWriteUnit::isCleanBundleManager($texts, $registry, 'texts'));
        self::assertFalse(TranslationYamlToolsWriteUnit::isCleanBundleManager($host, $registry, null), 'The default manager is never the bundle\'s.');
        self::assertFalse(TranslationYamlToolsWriteUnit::isCleanBundleManager($host, $registry, 'default'), 'Naming the default manager is the deprecated mode.');

        $private->persist(new TranslationOverride('en', 'messages', 'staged'));
        $texts->persist(new TranslationOverride('en', 'messages', 'staged'));
        self::assertFalse(TranslationYamlToolsWriteUnit::isCleanBundleManager($private, $registry, null), 'Rows staged with "flush: false" are pending.');
        self::assertFalse(TranslationYamlToolsWriteUnit::isCleanBundleManager($texts, $registry, 'texts'));

        $broken = $this->createStub(EntityManagerInterface::class);
        $broken->method('getUnitOfWork')->willThrowException(new RuntimeException('broken unit of work'));
        self::assertFalse(TranslationYamlToolsWriteUnit::isCleanBundleManager($broken, $registry, 'texts'), 'A unit of work that cannot compute its changes counts as pending.');
    }

    public function testTransientFailureIsReplayedInPlaceUntilItSucceeds(): void
    {
        $registry = $this->registry(['default' => self::BUNDLE]);
        $manager  = $this->schema($registry, 'default');
        $attempts = 0;

        $result = TranslationYamlToolsWriteUnit::runReplayingTransients($manager, $registry, static function () use (&$attempts): string {
            if (++$attempts < TranslationYamlToolsWriteUnit::REPLAY_ATTEMPTS) {
                throw self::deadlock();
            }

            return 'done';
        }, false);

        self::assertSame('done', $result);
        self::assertSame(TranslationYamlToolsWriteUnit::REPLAY_ATTEMPTS, $attempts);
    }

    public function testTransientFailureIsRethrownAfterTheLastReplay(): void
    {
        $registry = $this->registry(['default' => self::BUNDLE]);
        $manager  = $this->schema($registry, 'default');
        $attempts = 0;

        $failure = $this->failureOf(static function () use ($manager, $registry, &$attempts): void {
            TranslationYamlToolsWriteUnit::runReplayingTransients($manager, $registry, static function () use (&$attempts): never {
                ++$attempts;

                throw self::deadlock();
            }, false);
        });
        self::assertInstanceOf(TransientStorageFailure::class, $failure, 'The transient failure is rethrown.');
        self::assertInstanceOf(DeadlockException::class, $failure->getPrevious());
        self::assertSame(TranslationYamlToolsWriteUnit::REPLAY_ATTEMPTS, $attempts);
    }

    public function testInsideYourTransactionTheTransientFailureIsRethrownAtOnce(): void
    {
        $registry   = $this->registry(['default' => self::BUNDLE]);
        $manager    = $this->schema($registry, 'default');
        $connection = $manager->getConnection();
        $attempts   = 0;
        $connection->beginTransaction();

        $failure = $this->failureOf(static function () use ($manager, $registry, &$attempts): void {
            TranslationYamlToolsWriteUnit::runReplayingTransients($manager, $registry, static function () use (&$attempts): never {
                ++$attempts;

                throw self::deadlock();
            }, false);
        });

        self::assertInstanceOf(TransientStorageFailure::class, $failure);
        self::assertSame(1, $attempts, 'No replay inside your transaction: the decision is yours.');
        $connection->rollBack();
    }

    public function testUniqueViolationIsFoundAlongThePreviousChain(): void
    {
        $unique = new UniqueConstraintViolationException(new class('duplicate') extends AbstractException {
        }, null);

        self::assertTrue(TranslationYamlToolsWriteUnit::isUniqueViolation(new RuntimeException('wrapped', 0, $unique)));
        self::assertFalse(TranslationYamlToolsWriteUnit::isUniqueViolation(new RuntimeException('other', 0, self::deadlock())));
    }

    /**
     * @param array<string, array<string, string>> $mappings
     */
    private function registry(array $mappings): SqliteManagerRegistry
    {
        return new SqliteManagerRegistry($mappings, 'default', [new TranslationOverrideMetadataListener('nowo_translation_')]);
    }

    /**
     * What $call throws, or null.
     */
    private function failureOf(callable $call): ?Throwable
    {
        try {
            $call();
        } catch (Throwable $failure) {
            return $failure;
        }

        return null;
    }

    private static function deadlock(): DeadlockException
    {
        return new DeadlockException(new class('Deadlock found') extends AbstractException {
        }, null);
    }

    private function schema(SqliteManagerRegistry $registry, string $name): EntityManagerInterface
    {
        $manager = $this->manager($registry, $name);
        (new SchemaTool($manager))->createSchema([$manager->getClassMetadata(TranslationOverride::class)]);

        return $manager;
    }

    private function manager(SqliteManagerRegistry $registry, string $name): EntityManagerInterface
    {
        $manager = $registry->getManager($name);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);

        return $manager;
    }

    private function rowCount(EntityManagerInterface $manager): int
    {
        return (int) $manager->getConnection()->fetchOne('SELECT COUNT(*) FROM nowo_translation_override');
    }
}
