<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Tests\Unit\Doctrine;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Nowo\TranslationYamlToolsBundle\Doctrine\PrivateEntityManagerConnection;
use Nowo\TranslationYamlToolsBundle\Doctrine\PrivateEntityManagerMiddleware;
use Nowo\TranslationYamlToolsBundle\Doctrine\TranslationYamlToolsEntityManager;
use Nowo\TranslationYamlToolsBundle\Tests\Support\SqliteManagerRegistry;
use Nowo\TranslationYamlToolsBundle\TextOverride\Entity\TranslationOverride;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function dirname;

final class PrivateEntityManagerMiddlewareTest extends TestCase
{
    public function testRollbacksAndFailedCommitsMarkThePrivateManagerStale(): void
    {
        $private = $this->privateManager();
        $inner   = $this->createMock(DriverConnection::class);
        $inner->method('exec')->willReturn(0);
        $inner->expects(self::exactly(2))->method('commit')->willReturnOnConsecutiveCalls(null, self::throwException(new RuntimeException('commit failed')));
        $connection = new PrivateEntityManagerConnection($inner, $private);

        $connection->commit();
        $connection->exec('SAVEPOINT DOCTRINE_2');
        $connection->exec('RELEASE SAVEPOINT DOCTRINE_2');
        $connection->exec('UPDATE t SET a = 1');
        self::assertFalse($private->isStale(), 'Commits, savepoints and statements are not rollbacks.');

        $connection->exec('ROLLBACK TO SAVEPOINT DOCTRINE_2');
        self::assertTrue($private->isStale());

        $private->reset();
        $private->live();
        $connection->rollBack();
        self::assertTrue($private->isStale());

        $private->reset();
        $private->live();
        $connection->exec('ROLLBACK TRANSACTION DOCTRINE_3');
        self::assertTrue($private->isStale(), 'SQL Server savepoint rollback.');

        $private->reset();
        $private->live();
        try {
            $connection->commit();
            self::fail('The commit failure is rethrown.');
        } catch (RuntimeException) {
        }
        self::assertTrue($private->isStale());
    }

    public function testMiddlewareWrapsTheDriverConnections(): void
    {
        $private = $this->privateManager();
        $driver  = $this->createMock(Driver::class);
        $driver->method('connect')->willReturn($this->createMock(DriverConnection::class));

        $wrapped = (new PrivateEntityManagerMiddleware($private))->wrap($driver);

        self::assertInstanceOf(PrivateEntityManagerConnection::class, $wrapped->connect([]));
    }

    private function privateManager(): TranslationYamlToolsEntityManager
    {
        $private = new TranslationYamlToolsEntityManager(new SqliteManagerRegistry(['default' => ['Nowo\\TranslationYamlToolsBundle\\TextOverride\\Entity' => dirname(__DIR__, 3) . '/src/TextOverride/Entity']]), [TranslationOverride::class]);
        $private->live();

        return $private;
    }
}
