<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Tests\Integration\Concurrency;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\TransactionDoctrine\WriteUnit\CommitLedger;
use Nowo\TransactionDoctrine\WriteUnit\CommitMark;
use Nowo\TranslationYamlToolsBundle\Entity\MissingTranslationLog;
use Nowo\TranslationYamlToolsBundle\Entity\MissingTranslationLogStatus;
use Nowo\TranslationYamlToolsBundle\EventListener\TranslationYamlToolsConflictListener;
use Nowo\TranslationYamlToolsBundle\Repository\MissingTranslationLogRepository;
use Nowo\TranslationYamlToolsBundle\Tests\Kernel\ConcurrencyTestKernel;
use Nowo\TranslationYamlToolsBundle\TextOverride\Controller\TranslationOverrideController;
use Nowo\TranslationYamlToolsBundle\TextOverride\Entity\TranslationOverride;
use Nowo\TranslationYamlToolsBundle\TextOverride\TranslationOverrides;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Throwable;

use function sprintf;

/**
 * REQ-RUNTIME-003: two real connections on MySQL 8.4, MariaDB 11.4 and PostgreSQL 16 (CI job "Databases on …", run
 * with `--group database` and TRANSLATION_YAML_TOOLS_TEST_DATABASE_URL). Connection A is the application (the bundle's
 * private entity manager on the kernel's connection); connection B is another worker. Lock waits are capped at one
 * second on both, so a hang fails fast.
 */
#[Group('database')]
final class ConcurrencyRaceTest extends WebTestCase
{
    private const TABLE = 'nowo_translation_override';

    private const LOG_TABLE = 'nowo_translation_missing_log';

    private ?Connection $other = null;

    protected static function getKernelClass(): string
    {
        return ConcurrencyTestKernel::class;
    }

    protected function setUp(): void
    {
        if (ConcurrencyTestKernel::url() === '') {
            self::markTestSkipped(ConcurrencyTestKernel::URL_ENV . ' is not set (real-database job only).');
        }
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        if ($this->other instanceof Connection) {
            while ($this->other->isTransactionActive()) {
                $this->other->rollBack();
            }
            $this->other->close();
            $this->other = null;
        }
        parent::tearDown();
    }

    public function testLockWaitOnTheDeskAnswers409Conflict001AndTheRetrySaves(): void
    {
        $client = $this->client();
        $this->overrides()->set('en', 'messages', 'site.topline.hours', 'Seed');

        $crawler = $client->request('GET', '/_translation_yaml_tools/texts/edit?key=site.topline.hours');
        self::assertResponseIsSuccessful();
        $form              = $crawler->filter('[data-testid="tyt-overrides-form"]')->form();
        $form['texts[en]'] = 'From A';

        // Another worker holds the row (an uncommitted edit).
        $other = $this->other();
        $other->beginTransaction();
        $other->executeStatement(sprintf("UPDATE %s SET value = 'From B' WHERE locale = 'en' AND message_key = 'site.topline.hours'", self::TABLE));

        $client->submit($form);
        $response = $client->getResponse();
        self::assertSame(409, $response->getStatusCode());
        self::assertSame('CONFLICT_001', $response->headers->get('X-Conflict-Code'));
        self::assertSame('1', $response->headers->get('Retry-After'));
        self::assertSame(0, $this->connection()->getTransactionNestingLevel(), 'The connection is usable after the conflict.');

        $other->rollBack();
        self::assertSame('Seed', $this->value('en', 'site.topline.hours'), 'Nothing of the failed save was written.');

        $client->submit($form);
        self::assertResponseRedirects('/_translation_yaml_tools/texts/edit?key=site.topline.hours');
        self::assertSame('From A', $this->value('en', 'site.topline.hours'), 'The retry saves.');
        self::assertSame(1, $this->rowsOf('en', 'site.topline.hours'));
    }

    public function testTwoFirstSavesOfAMessageKeepOneRowAndTheLastSaveWins(): void
    {
        $this->client();
        $overrides = $this->overrides();
        $other     = $this->other();
        $runs      = 0;

        $overrides->write(static function () use ($overrides, $other, &$runs): void {
            $overrides->set('en', 'messages', 'site.greeting', 'Hi A %name%', false);
            if (++$runs === 1) {
                // The other worker's first save of the same message commits between our read and our insert.
                $other->insert(self::TABLE, ['locale' => 'en', 'domain' => 'messages', 'message_key' => 'site.greeting', 'value' => 'Hi B %name%', 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00']);
            }
        });

        self::assertSame(2, $runs, 'The unique violation ran the unit once more (the manager was replaced in between).');
        self::assertSame(1, $this->rowsOf('en', 'site.greeting'), 'One row, never a duplicate.');
        self::assertSame('Hi A %name%', $this->value('en', 'site.greeting'), 'The last save wins.');
        $translator = self::getContainer()->get('translator');
        self::assertInstanceOf(TranslatorInterface::class, $translator);
        self::assertSame('Hi A Ana', $translator->trans('site.greeting', ['%name%' => 'Ana'], 'messages', 'en'));
        self::assertSame(0, $this->connection()->getTransactionNestingLevel());
    }

    public function testCommittedThenConflictAnswersConflict002WithoutRetryAfter(): void
    {
        $this->client();
        $overrides = $this->overrides();
        $overrides->set('es', 'messages', 'site.topline.hours', 'Semilla');
        $other = $this->other();
        $other->beginTransaction();
        $other->executeStatement(sprintf("UPDATE %s SET value = 'B' WHERE locale = 'es' AND message_key = 'site.topline.hours'", self::TABLE));

        $failure = null;
        try {
            $overrides->set('es', 'messages', 'site.topline.hours', 'A');
        } catch (TransientStorageFailure $caught) {
            $failure = $caught;
        }
        $other->rollBack();
        self::assertInstanceOf(TransientStorageFailure::class, $failure, 'The lock wait fails as a transient failure.');

        $listener = self::getContainer()->get('test.concurrency.' . TranslationYamlToolsConflictListener::class);
        self::assertInstanceOf(TranslationYamlToolsConflictListener::class, $listener);

        $fresh = $this->conflictEvent($failure, null);
        $listener($fresh);
        $answer = $fresh->getThrowable();
        self::assertInstanceOf(HttpException::class, $answer);
        self::assertSame(['X-Conflict-Code' => 'CONFLICT_001', 'Retry-After' => '1'], $answer->getHeaders());

        // The same failure after an earlier commit of the same request (transaction-doctrine's request commit mark).
        $ledger    = new CommitLedger();
        $committed = $this->conflictEvent($failure, new CommitMark($ledger));
        $ledger->record();
        $listener($committed);
        $answer = $committed->getThrowable();
        self::assertInstanceOf(HttpException::class, $answer);
        self::assertSame(409, $answer->getStatusCode());
        self::assertSame(['X-Conflict-Code' => 'CONFLICT_002'], $answer->getHeaders());
        self::assertSame('Semilla', $this->value('es', 'site.topline.hours'));
    }

    public function testConcurrentMissingLogUpsertsNeverDuplicateNorLoseHits(): void
    {
        $this->client();
        $repository = self::getContainer()->get('test.concurrency.' . MissingTranslationLogRepository::class);
        self::assertInstanceOf(MissingTranslationLogRepository::class, $repository);
        $key   = ['hits' => 2, 'messageId' => 'race.key', 'domain' => 'messages', 'locale' => 'en'];
        $other = $this->other();

        // The other worker's buffer created the same key and has not committed yet: ours waits, replays, then fails.
        $other->beginTransaction();
        $other->insert(self::LOG_TABLE, [
            $this->column('messageId')   => 'race.key',
            $this->column('domain')      => 'messages',
            $this->column('locale')      => 'en',
            $this->column('status')      => 'pending',
            $this->column('hitCount')    => 5,
            $this->column('firstSeenAt') => '2026-01-01 00:00:00',
            $this->column('lastSeenAt')  => '2026-01-01 00:00:00',
        ]);
        try {
            $repository->persistBuffer(['k' => $key]);
            self::fail('The lock wait must fail after the replays.');
        } catch (TransientStorageFailure) {
        }
        self::assertSame(0, $this->connection()->getTransactionNestingLevel());
        $other->commit();

        $repository->persistBuffer(['k' => $key]);
        $repository->persistBuffer(['k' => $key]);

        $rows = $this->connection()->fetchFirstColumn(sprintf("SELECT %s FROM %s WHERE %s = 'race.key'", $this->column('hitCount'), self::LOG_TABLE, $this->column('messageId')));
        self::assertCount(1, $rows, 'One row per key, whatever the order of the writers.');
        self::assertSame(9, (int) $rows[0], 'No hit lost and none counted twice (the failed attempts rolled back whole).');
    }

    public function testStatusChangeRacingAnUpsertKeepsBothWrites(): void
    {
        $this->client();
        $repository = self::getContainer()->get('test.concurrency.' . MissingTranslationLogRepository::class);
        self::assertInstanceOf(MissingTranslationLogRepository::class, $repository);
        $repository->persistBuffer(['k' => ['hits' => 1, 'messageId' => 'status.key', 'domain' => 'messages', 'locale' => 'en']]);
        $id  = (int) $this->connection()->fetchOne(sprintf("SELECT id FROM %s WHERE %s = 'status.key'", self::LOG_TABLE, $this->column('messageId')));
        $row = $repository->findOneById($id);
        self::assertInstanceOf(MissingTranslationLog::class, $row);

        // Another worker adds hits after we loaded the row; our "mark added" writes only the status columns.
        $hits = $this->column('hitCount');
        $this->other()->executeStatement(sprintf('UPDATE %s SET %s = %s + 4 WHERE id = %d', self::LOG_TABLE, $hits, $hits, $id));
        $row->setStatus(MissingTranslationLogStatus::Added);
        $repository->flush();

        $stored = $this->connection()->fetchNumeric(sprintf('SELECT status, %s FROM %s WHERE id = %d', $hits, self::LOG_TABLE, $id));
        self::assertIsArray($stored);
        self::assertSame(['added', 5], [$stored[0], (int) $stored[1]]);
    }

    private function client(): KernelBrowser
    {
        $client = self::createClient();
        $client->disableReboot();
        $manager = $this->doctrine()->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $schema  = new SchemaTool($manager);
        $classes = [$manager->getClassMetadata(TranslationOverride::class), $manager->getClassMetadata(MissingTranslationLog::class)];
        $schema->dropSchema($classes);
        $schema->createSchema($classes);
        $this->capLockWaits($this->connection());

        return $client;
    }

    /**
     * The physical column of a missing-log field (the host's naming strategy decides it).
     */
    private function column(string $field): string
    {
        $manager = $this->doctrine()->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $manager);

        return $manager->getClassMetadata(MissingTranslationLog::class)->getColumnName($field);
    }

    private function overrides(): TranslationOverrides
    {
        $overrides = self::getContainer()->get('test.concurrency.' . TranslationOverrides::class);
        self::assertInstanceOf(TranslationOverrides::class, $overrides);

        return $overrides;
    }

    private function doctrine(): ManagerRegistry
    {
        $doctrine = self::getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $doctrine);

        return $doctrine;
    }

    private function connection(): Connection
    {
        $connection = $this->doctrine()->getConnection();
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }

    private function other(): Connection
    {
        if ($this->other instanceof Connection) {
            return $this->other;
        }
        $params = (new DsnParser(['mysql' => 'pdo_mysql', 'postgresql' => 'pdo_pgsql']))->parse(ConcurrencyTestKernel::url());
        $other  = DriverManager::getConnection($params);
        $this->capLockWaits($other);

        return $this->other = $other;
    }

    private function capLockWaits(Connection $connection): void
    {
        $connection->executeStatement($connection->getDatabasePlatform() instanceof AbstractMySQLPlatform
            ? 'SET SESSION innodb_lock_wait_timeout = 1'
            : "SET lock_timeout = '1s'");
    }

    private function value(string $locale, string $key): ?string
    {
        $value = $this->connection()->fetchOne(sprintf('SELECT value FROM %s WHERE locale = ? AND message_key = ?', self::TABLE), [$locale, $key]);

        return $value === false ? null : (string) $value;
    }

    private function rowsOf(string $locale, string $key): int
    {
        return (int) $this->connection()->fetchOne(sprintf('SELECT COUNT(*) FROM %s WHERE locale = ? AND message_key = ?', self::TABLE), [$locale, $key]);
    }

    private function conflictEvent(Throwable $failure, ?CommitMark $mark): ExceptionEvent
    {
        $request = Request::create('/_translation_yaml_tools/texts/edit', 'POST');
        $request->attributes->set('_controller', TranslationOverrideController::class . '::edit');
        if ($mark instanceof CommitMark) {
            $request->attributes->set(CommitMark::REQUEST_ATTRIBUTE, $mark);
        }

        return new ExceptionEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, $failure);
    }
}
