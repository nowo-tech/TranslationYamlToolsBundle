<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Tests\Unit\Repository;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\OraclePlatform;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Exception;
use Nowo\TranslationYamlToolsBundle\Entity\MissingTranslationLog;
use Nowo\TranslationYamlToolsBundle\Entity\MissingTranslationLogStatus;
use Nowo\TranslationYamlToolsBundle\Repository\MissingTranslationLogRepository;
use Nowo\TranslationYamlToolsBundle\Tests\Fixtures\MissingTranslationLogTestEntityManagerFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

use function in_array;
use function str_contains;
use function str_replace;
use function str_starts_with;
use function strlen;

#[CoversClass(MissingTranslationLogRepository::class)]
final class MissingTranslationLogRepositoryTest extends TestCase
{
    private function createRepository(): MissingTranslationLogRepository
    {
        return MissingTranslationLogTestEntityManagerFactory::createRepository();
    }

    /**
     * Real metadata + mocked connection so {@see MissingTranslationLogRepository::persistBuffer}
     * exercises MySQL / portable-fallback upsert paths (SQLite factory never reaches them).
     *
     * @return array{0: MissingTranslationLogRepository, 1: Connection&MockObject}
     */
    private function createRepositoryWithPlatform(AbstractPlatform $platform): array
    {
        $sqliteRepo = MissingTranslationLogTestEntityManagerFactory::createRepository();
        $realEm     = (new ReflectionMethod(MissingTranslationLogRepository::class, 'getEntityManager'))->invoke($sqliteRepo);
        $meta       = $realEm->getClassMetadata(MissingTranslationLog::class);

        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn($platform);
        $connection->method('quoteSingleIdentifier')->willReturnCallback(
            static fn (string $name): string => '"' . str_replace('"', '""', $name) . '"',
        );

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);
        $em->method('getClassMetadata')->with(MissingTranslationLog::class)->willReturn($meta);

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->with(MissingTranslationLog::class)->willReturn($em);
        $registry->method('getManager')->willReturn($em);

        return [new MissingTranslationLogRepository($registry), $connection];
    }

    private function uniqueViolation(): UniqueConstraintViolationException
    {
        $driver = new class('dup') extends Exception implements DriverException {
            public function getSQLState(): string
            {
                return '23000';
            }
        };

        return new UniqueConstraintViolationException($driver, null);
    }

    public function testPersistBufferEmptyIsNoOp(): void
    {
        $repo = $this->createRepository();
        $repo->persistBuffer([]);
        self::assertSame(0, $repo->count([]));
    }

    public function testPersistBufferInsertAndDuplicateUpdatesHits(): void
    {
        $repo = $this->createRepository();
        $repo->persistBuffer([
            'a' => [
                'hits'      => 1,
                'messageId' => 'k1',
                'domain'    => 'messages',
                'locale'    => 'es',
                'callSite'  => null,
            ],
        ]);
        $repo->persistBuffer([
            'a' => [
                'hits'      => 2,
                'messageId' => 'k1',
                'domain'    => 'messages',
                'locale'    => 'es',
                'callSite'  => '/tmp/x.php:1',
            ],
        ]);

        $rows = $repo->findByStatus(MissingTranslationLogStatus::Pending, 10);
        self::assertCount(1, $rows);
        self::assertSame(3, $rows[0]->getHitCount());
        self::assertSame('/tmp/x.php:1', $rows[0]->getCallSite());
    }

    public function testFindByStatusAndFindOneById(): void
    {
        $repo = $this->createRepository();
        $repo->persistBuffer([
            'a' => [
                'hits'      => 1,
                'messageId' => 'id.a',
                'domain'    => 'd',
                'locale'    => 'en',
                'callSite'  => null,
            ],
        ]);
        $id = $repo->findByStatus(MissingTranslationLogStatus::Pending, 5)[0]->getId();
        self::assertNotNull($id);
        $one = $repo->findOneById($id);
        self::assertNotNull($one);
        self::assertSame('id.a', $one->getMessageId());
    }

    public function testPersistBufferTruncatesLongCallSite(): void
    {
        $long = str_repeat('x', 1100);
        $repo = $this->createRepository();
        $repo->persistBuffer([
            'a' => [
                'hits'      => 1,
                'messageId' => 'k',
                'domain'    => 'm',
                'locale'    => 'es',
                'callSite'  => $long,
            ],
        ]);
        $site = $repo->findByStatus(MissingTranslationLogStatus::Pending, 1)[0]->getCallSite();
        self::assertNotNull($site);
        self::assertSame(1024, strlen($site));
        self::assertStringEndsWith('...', $site);
    }

    public function testPersistBufferTruncatesLongMessageId(): void
    {
        $long = str_repeat('k', 600);
        $repo = $this->createRepository();
        $repo->persistBuffer([
            'a' => [
                'hits'      => 1,
                'messageId' => $long,
                'domain'    => 'messages',
                'locale'    => 'es',
                'callSite'  => null,
            ],
        ]);
        $messageId = $repo->findByStatus(MissingTranslationLogStatus::Pending, 1)[0]->getMessageId();
        self::assertSame(500, strlen($messageId));
        self::assertStringEndsWith('...', $messageId);
    }

    public function testPersistBufferInsertsRequestFieldsAndDuplicateUpdatesThem(): void
    {
        $repo = $this->createRepository();
        $repo->persistBuffer([
            'a' => [
                'hits'          => 1,
                'messageId'     => 'k1',
                'domain'        => 'messages',
                'locale'        => 'es',
                'callSite'      => null,
                'requestRoute'  => 'r1',
                'requestMethod' => 'POST',
                'requestPath'   => '/a',
            ],
        ]);
        $repo->clearManaged();
        $row = $repo->findByStatus(MissingTranslationLogStatus::Pending, 1)[0];
        self::assertSame('r1', $row->getRequestRoute());
        self::assertSame('POST', $row->getRequestMethod());
        self::assertSame('/a', $row->getRequestPath());

        $repo->persistBuffer([
            'a' => [
                'hits'          => 1,
                'messageId'     => 'k1',
                'domain'        => 'messages',
                'locale'        => 'es',
                'callSite'      => '/f.php:1',
                'requestRoute'  => 'r2',
                'requestMethod' => 'PATCH',
                'requestPath'   => '/b',
            ],
        ]);
        $repo->clearManaged();
        $row2 = $repo->findByStatus(MissingTranslationLogStatus::Pending, 1)[0];
        self::assertSame(2, $row2->getHitCount());
        self::assertSame('/f.php:1', $row2->getCallSite());
        self::assertSame('r2', $row2->getRequestRoute());
        self::assertSame('PATCH', $row2->getRequestMethod());
        self::assertSame('/b', $row2->getRequestPath());
    }

    public function testPersistBufferDuplicateWithoutRequestFieldsPreservesRequestContext(): void
    {
        $repo = $this->createRepository();
        $repo->persistBuffer([
            'a' => [
                'hits'          => 1,
                'messageId'     => 'k1',
                'domain'        => 'messages',
                'locale'        => 'es',
                'callSite'      => null,
                'requestRoute'  => 'keep_me',
                'requestMethod' => 'GET',
                'requestPath'   => '/first',
            ],
        ]);
        $repo->persistBuffer([
            'a' => [
                'hits'      => 1,
                'messageId' => 'k1',
                'domain'    => 'messages',
                'locale'    => 'es',
                'callSite'  => '/only.php:9',
            ],
        ]);

        $row = $repo->findByStatus(MissingTranslationLogStatus::Pending, 1)[0];
        self::assertSame(2, $row->getHitCount());
        self::assertSame('/only.php:9', $row->getCallSite());
        self::assertSame('keep_me', $row->getRequestRoute());
        self::assertSame('GET', $row->getRequestMethod());
        self::assertSame('/first', $row->getRequestPath());
    }

    public function testPersistBufferTruncatesLongRequestPath(): void
    {
        $long = '/' . str_repeat('p', 2100);
        $repo = $this->createRepository();
        $repo->persistBuffer([
            'a' => [
                'hits'          => 1,
                'messageId'     => 'k',
                'domain'        => 'm',
                'locale'        => 'es',
                'callSite'      => null,
                'requestRoute'  => null,
                'requestMethod' => 'GET',
                'requestPath'   => $long,
            ],
        ]);
        $path = $repo->findByStatus(MissingTranslationLogStatus::Pending, 1)[0]->getRequestPath();
        self::assertNotNull($path);
        self::assertSame(2048, strlen($path));
        self::assertStringEndsWith('...', $path);
    }

    public function testClearAllDeletesRowsAndReturnsCount(): void
    {
        $repo = $this->createRepository();
        $repo->persistBuffer([
            'a' => [
                'hits'      => 1,
                'messageId' => 'k1',
                'domain'    => 'messages',
                'locale'    => 'en',
                'callSite'  => null,
            ],
            'b' => [
                'hits'      => 1,
                'messageId' => 'k2',
                'domain'    => 'messages',
                'locale'    => 'en',
                'callSite'  => null,
            ],
        ]);

        self::assertSame(2, $repo->count([]));
        self::assertSame(2, $repo->clearAll());
        self::assertSame(0, $repo->count([]));
    }

    public function testClearByStatusDeletesOnlyGivenStatus(): void
    {
        $repo = $this->createRepository();
        $repo->persistBuffer([
            'a' => [
                'hits'      => 1,
                'messageId' => 'k1',
                'domain'    => 'messages',
                'locale'    => 'en',
                'callSite'  => null,
            ],
            'b' => [
                'hits'      => 1,
                'messageId' => 'k2',
                'domain'    => 'messages',
                'locale'    => 'en',
                'callSite'  => null,
            ],
        ]);

        $rows = $repo->findByStatus(MissingTranslationLogStatus::Pending, 10);
        self::assertCount(2, $rows);
        $rows[0]->setStatus(MissingTranslationLogStatus::Added);
        $repo->flush();
        $repo->clearManaged();

        self::assertSame(1, $repo->clearByStatus(MissingTranslationLogStatus::Pending));
        self::assertCount(0, $repo->findByStatus(MissingTranslationLogStatus::Pending, 10));
        self::assertCount(1, $repo->findByStatus(MissingTranslationLogStatus::Added, 10));
    }

    public function testPersistBufferUsesMySqlOnDuplicateKeyUpsert(): void
    {
        [$repo, $connection] = $this->createRepositoryWithPlatform(new MySQLPlatform());

        $connection->expects(self::once())
            ->method('executeStatement')
            ->with(
                self::callback(static function (string $sql): bool {
                    return str_contains($sql, 'ON DUPLICATE KEY UPDATE')
                        && str_contains($sql, 'INSERT INTO');
                }),
                self::callback(static function (array $params): bool {
                    return $params['messageId'] === 'k1'
                        && $params['domain'] === 'messages'
                        && $params['locale'] === 'es'
                        && $params['hits'] === 2
                        && $params['callSite'] === '/a.php:1'
                        && $params['requestRoute'] === 'route'
                        && $params['requestMethod'] === 'GET'
                        && $params['requestPath'] === '/path';
                }),
                self::isType('array'),
            )
            ->willReturn(1);

        $repo->persistBuffer([
            'a' => [
                'hits'          => 2,
                'messageId'     => 'k1',
                'domain'        => 'messages',
                'locale'        => 'es',
                'callSite'      => '/a.php:1',
                'requestRoute'  => 'route',
                'requestMethod' => 'GET',
                'requestPath'   => '/path',
            ],
        ]);
    }

    public function testPersistBufferFallbackUpdatesExistingRow(): void
    {
        [$repo, $connection] = $this->createRepositoryWithPlatform(new OraclePlatform());

        $connection->expects(self::once())
            ->method('executeStatement')
            ->with(
                self::callback(static function (string $sql): bool {
                    return str_starts_with($sql, 'UPDATE ')
                        && str_contains($sql, 'callSite')
                        && str_contains($sql, 'requestRoute')
                        && str_contains($sql, 'requestMethod')
                        && str_contains($sql, 'requestPath');
                }),
                self::isType('array'),
                self::isType('array'),
            )
            ->willReturn(1);
        $connection->expects(self::never())->method('insert');

        $repo->persistBuffer([
            'a' => [
                'hits'          => 3,
                'messageId'     => 'k1',
                'domain'        => 'messages',
                'locale'        => 'es',
                'callSite'      => '/b.php:2',
                'requestRoute'  => 'r',
                'requestMethod' => 'POST',
                'requestPath'   => '/p',
            ],
        ]);
    }

    public function testPersistBufferFallbackInsertsWhenNoExistingRow(): void
    {
        [$repo, $connection] = $this->createRepositoryWithPlatform(new OraclePlatform());

        $connection->expects(self::once())
            ->method('executeStatement')
            ->willReturn(0);
        $connection->expects(self::once())
            ->method('insert')
            ->with(
                self::isType('string'),
                self::callback(static function (array $data): bool {
                    return in_array('k1', $data, true)
                        && in_array('messages', $data, true)
                        && in_array(MissingTranslationLogStatus::Pending->value, $data, true);
                }),
                self::isType('array'),
            )
            ->willReturn(1);

        $repo->persistBuffer([
            'a' => [
                'hits'      => 1,
                'messageId' => 'k1',
                'domain'    => 'messages',
                'locale'    => 'es',
                'callSite'  => null,
            ],
        ]);
    }

    public function testPersistBufferFallbackCatchesUniqueConstraintAndUpdates(): void
    {
        [$repo, $connection] = $this->createRepositoryWithPlatform(new OraclePlatform());

        $connection->expects(self::exactly(2))
            ->method('executeStatement')
            ->willReturnOnConsecutiveCalls(0, 1);
        $connection->expects(self::once())
            ->method('insert')
            ->willThrowException($this->uniqueViolation());

        $repo->persistBuffer([
            'a' => [
                'hits'      => 1,
                'messageId' => 'race',
                'domain'    => 'messages',
                'locale'    => 'en',
                'callSite'  => '',
            ],
        ]);
    }

    public function testPersistBufferTruncatesLongDomainAndLocale(): void
    {
        $repo = $this->createRepository();
        $repo->persistBuffer([
            'a' => [
                'hits'      => 1,
                'messageId' => 'k',
                'domain'    => str_repeat('d', 200),
                'locale'    => str_repeat('l', 40),
                'callSite'  => null,
            ],
        ]);
        $row = $repo->findByStatus(MissingTranslationLogStatus::Pending, 1)[0];
        self::assertSame(180, strlen($row->getDomain()));
        self::assertStringEndsWith('...', $row->getDomain());
        self::assertSame(32, strlen($row->getLocale()));
    }

    public function testPersistBufferTruncatesLongRequestRouteAndMethod(): void
    {
        $repo = $this->createRepository();
        $repo->persistBuffer([
            'a' => [
                'hits'          => 1,
                'messageId'     => 'k',
                'domain'        => 'm',
                'locale'        => 'es',
                'callSite'      => null,
                'requestRoute'  => str_repeat('r', 200),
                'requestMethod' => 'VERYLONGMETHOD',
                'requestPath'   => '',
            ],
        ]);
        $row = $repo->findByStatus(MissingTranslationLogStatus::Pending, 1)[0];
        self::assertNotNull($row->getRequestRoute());
        self::assertSame(180, strlen($row->getRequestRoute()));
        self::assertSame(8, strlen((string) $row->getRequestMethod()));
        self::assertNull($row->getRequestPath());
    }
}
