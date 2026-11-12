<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Tests\Integration\PrivateEntityManager;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\Decorator\EntityManagerDecorator;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\ORM\UnitOfWork;
use InvalidArgumentException;
use Nowo\TranslationYamlToolsBundle\Doctrine\TranslationYamlToolsEntityManager;
use Nowo\TranslationYamlToolsBundle\Entity\MissingTranslationLog;
use Nowo\TranslationYamlToolsBundle\Entity\MissingTranslationLogStatus;
use Nowo\TranslationYamlToolsBundle\Repository\MissingTranslationLogRepository;
use Nowo\TranslationYamlToolsBundle\Tests\Fixtures\Host\HostNote;
use Nowo\TranslationYamlToolsBundle\Tests\Kernel\PrivateEntityManagerTestKernel;
use Nowo\TranslationYamlToolsBundle\TextOverride\Entity\TranslationOverride;
use Nowo\TranslationYamlToolsBundle\TextOverride\Repository\TranslationOverrideRepository;
use Nowo\TranslationYamlToolsBundle\TextOverride\TranslationOverrideEditor;
use Nowo\TranslationYamlToolsBundle\TextOverride\TranslationOverrides;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use ReflectionProperty;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\FinishRequestEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Throwable;

use function restore_error_handler;
use function set_error_handler;
use function sprintf;

use const E_USER_DEPRECATED;

/**
 * REQ-ARCH-001 rule 6 for the standard (zero-config) host, 1.18.0: every bundle write goes through the bundle's
 * private entity manager, never through the application's default manager. One connection, one default manager mapping
 * the host's {@see HostNote} and the bundle entities.
 */
final class PrivateEntityManagerTest extends KernelTestCase
{
    private EntityManagerInterface $host;

    private Connection $connection;

    private TranslationYamlToolsEntityManager $private;

    protected static function getKernelClass(): string
    {
        return PrivateEntityManagerTestKernel::class;
    }

    protected function setUp(): void
    {
        // debug false: the DBAL debug middleware keeps every query until kernel.reset, which a worker never runs.
        self::bootKernel(['debug' => false]);
        $host             = $this->service('test.pem.host', EntityManagerInterface::class);
        $this->host       = $host;
        $this->connection = $host->getConnection();
        (new SchemaTool($host))->createSchema($host->getMetadataFactory()->getAllMetadata());
        $this->private = $this->service('test.pem.private', TranslationYamlToolsEntityManager::class);
    }

    public function testEveryWritePathLeavesTheHostPendingChangesUntouched(): void
    {
        $note = new HostNote('pending');
        $this->host->persist($note);
        $this->host->getFilters()->enable('tenant')->setParameter('tenant', 'acme');

        $overrides = $this->overrides();
        $editor    = $this->editor();
        $log       = $this->logRepository();
        $kernel    = self::$kernel;
        self::assertNotNull($kernel);
        $commands = new Application($kernel);

        $paths = [
            'overrides set (insert)' => static fn () => $overrides->set('en', 'messages', 'site.greeting', 'Hi %name%'),
            'overrides set (update)' => static fn () => $overrides->set('en', 'messages', 'site.greeting', 'Hey %name%'),
            'overrides set (delete)' => static fn () => $overrides->set('en', 'messages', 'site.greeting', null),
            'editor save'            => static fn () => $editor->save('messages', 'site.greeting', ['en' => 'Yo %name%', 'es' => 'Hola %name%!']),
            'editor save + flush'    => static function () use ($editor): void {
                $editor->save('messages', 'site.topline.hours', ['en' => 'Always open'], flush: false);
                $editor->flush();
            },
            'editor reset'              => static fn () => $editor->reset('messages', 'site.topline.hours'),
            'missing log persistBuffer' => static fn () => $log->persistBuffer([
                'a' => ['hits' => 2, 'messageId' => 'missing.one', 'domain' => 'messages', 'locale' => 'en'],
                'b' => ['hits' => 1, 'messageId' => 'missing.two', 'domain' => 'messages', 'locale' => 'es'],
            ]),
            'missing log mark added' => function () use ($commands): void {
                $tester = new CommandTester($commands->find('nowo:translation-yaml:missing-log-mark-added'));
                self::assertSame(Command::SUCCESS, $tester->execute(['id' => (string) $this->logId('missing.one')]));
            },
            'missing log validate' => function () use ($commands): void {
                $tester = new CommandTester($commands->find('nowo:translation-yaml:missing-log-validate'));
                self::assertSame(Command::SUCCESS, $tester->execute(['id' => (string) $this->logId('missing.two'), '--note' => 'ok']));
            },
            'missing log repository flush' => function () use ($log): void {
                $row = $log->findOneById($this->logId('missing.one'));
                self::assertInstanceOf(MissingTranslationLog::class, $row);
                $row->setStatus(MissingTranslationLogStatus::Pending);
                $log->flush();
            },
            'missing log clear status' => static fn (): int => $log->clearByStatus(MissingTranslationLogStatus::Validated),
        ];
        foreach ($paths as $path => $write) {
            $write();
            self::assertTrue($this->host->getUnitOfWork()->isScheduledForInsert($note), $path . ': the host insert is still pending.');
            self::assertSame(0, $this->rows('host_note'), $path . ': nothing of the host was flushed.');
            self::assertTrue($this->host->isOpen());
        }

        self::assertSame(['en' => 'Yo %name%', 'es' => 'Hola %name%!'], $this->overrideTexts());
        self::assertSame(['missing.one' => 'pending'], $this->logStatuses());
        self::assertSame(1, $log->clearAll());
        self::assertSame(0, $this->rows('host_note'));

        $this->host->flush();
        self::assertSame(1, $this->rows('host_note'), 'The host flushes its own changes when it decides to.');
    }

    public function testBundleEntitiesAreNeverManagedByTheHostManager(): void
    {
        $this->overrides()->set('en', 'messages', 'site.greeting', 'Hi %name%');
        $row = $this->overrideRepository()->findOne('en', 'messages', 'site.greeting');
        self::assertInstanceOf(TranslationOverride::class, $row);
        self::assertFalse($this->host->contains($row));
        self::assertTrue($this->private->live()->contains($row));
        self::assertSame($this->connection, $this->private->getConnection(), 'Same connection: same tables, same transaction.');
    }

    public function testHostTransactionRollbackThenTheSameSaveWritesTheRow(): void
    {
        $overrides = $this->overrides();
        $overrides->set('en', 'messages', 'site.greeting', 'A %name%');

        $this->connection->beginTransaction();
        $overrides->set('en', 'messages', 'site.greeting', 'B %name%');
        $this->connection->rollBack();

        self::assertSame('A %name%', $this->overrideTexts()['en'], 'The host rolled the bundle write back.');
        self::assertTrue($this->private->isStale());
        $overrides->set('en', 'messages', 'site.greeting', 'B %name%');
        self::assertSame('B %name%', $this->overrideTexts()['en'], 'The repeated save is not lost to a stale identity map.');
        self::assertFalse($this->private->isStale());
    }

    public function testStaleManagerWithPendingWorkIsKept(): void
    {
        $overrides = $this->overrides();
        $overrides->set('en', 'messages', 'site.greeting', 'A %name%');
        $row = $this->overrideRepository()->findOne('en', 'messages', 'site.greeting');
        self::assertInstanceOf(TranslationOverride::class, $row);
        $row->setValue('Edited %name%');

        $this->connection->beginTransaction();
        $this->connection->rollBack(); // an unrelated host rollback
        self::assertTrue($this->private->isStale());

        self::assertNull($this->overrideRepository()->findOne('es', 'messages', 'site.greeting'));
        self::assertTrue($this->private->live()->contains($row), 'The stale manager with a pending update is kept.');
        $overrides->flush();
        self::assertSame('Edited %name%', $this->overrideTexts()['en']);
    }

    public function testFailedBundleFlushClosesOnlyThePrivateManagerAndItIsRecreated(): void
    {
        $note = new HostNote('pending');
        $this->host->persist($note);
        $repository = $this->overrideRepository();
        $repository->persist(new TranslationOverride('en', 'messages', 'site.dup'));
        $repository->persist(new TranslationOverride('en', 'messages', 'site.dup'));
        $closed = $this->private->live();

        try {
            $repository->flush();
            self::fail('A duplicate override must fail.');
        } catch (Throwable) {
        }

        self::assertFalse($closed->isOpen(), 'The failed flush closed the private manager.');
        self::assertTrue($this->host->isOpen(), 'The host manager was never closed or reset.');
        self::assertTrue($this->host->getUnitOfWork()->isScheduledForInsert($note));
        $this->overrides()->set('en', 'messages', 'site.greeting', 'Works %name%');
        self::assertNotSame($closed, $this->private->live(), 'A new private manager was created.');
        self::assertSame(['en' => 'Works %name%'], $this->overrideTexts());
        self::assertSame(0, $this->rows('host_note'));

        // Same for the missing log: its flush fails on a closed private manager's duplicate, the host stays untouched.
        $this->logRepository()->persistBuffer(['a' => ['hits' => 1, 'messageId' => 'm', 'domain' => 'messages', 'locale' => 'en']]);
        $row = $this->logRepository()->findOneById($this->logId('m'));
        self::assertInstanceOf(MissingTranslationLog::class, $row);
        $this->private->persist(new MissingTranslationLog('m', 'messages', 'en', new DateTimeImmutable()));
        try {
            $this->logRepository()->flush();
            self::fail('A duplicate missing-log row must fail.');
        } catch (Throwable) {
        }
        self::assertTrue($this->host->isOpen());
        self::assertSame(1, $this->rows('nowo_translation_missing_log'));
    }

    /**
     * Audit NX: a private manager whose unit of work throws while computing change sets (a wrongly typed association, a
     * throwing listener) and whose clear() throws is still dropped at the next boundary; the boundary never throws and
     * the next unit gets a fresh, working manager. The host manager is untouched.
     */
    public function testABrokenPrivateManagerIsDroppedAtTheNextBoundary(): void
    {
        $note = new HostNote('pending');
        $this->host->persist($note);
        $this->overrides()->set('en', 'messages', 'site.greeting', 'Before %name%');
        $live       = $this->private->live();
        $unitOfWork = $this->createStub(UnitOfWork::class);
        $unitOfWork->method('computeChangeSets')->willThrowException(new RuntimeException('broken unit of work'));
        $broken = new class($live, $unitOfWork) extends EntityManagerDecorator {
            public function __construct(EntityManagerInterface $wrapped, private readonly UnitOfWork $unitOfWork)
            {
                parent::__construct($wrapped);
            }

            public function getUnitOfWork(): UnitOfWork
            {
                return $this->unitOfWork;
            }

            public function clear(): void
            {
                throw new RuntimeException('broken clear');
            }
        };
        (new ReflectionProperty(TranslationYamlToolsEntityManager::class, 'current'))->setValue($this->private, $broken);

        $kernel     = $this->service('http_kernel', HttpKernelInterface::class);
        $dispatcher = $this->service('event_dispatcher', EventDispatcherInterface::class);
        $request    = Request::create('/');
        // Only the boundary listeners (PHP_INT_MAX) run: the test kernel has no route for the router listener.
        $stop = static function (RequestEvent $event): void {
            $event->stopPropagation();
        };
        $dispatcher->addListener(KernelEvents::REQUEST, $stop, 1024);
        try {
            $dispatcher->dispatch(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST), KernelEvents::REQUEST);
        } finally {
            $dispatcher->removeListener(KernelEvents::REQUEST, $stop);
        }

        self::assertFalse($this->private->isCreated(), 'The broken manager was dropped first.');
        $this->overrides()->set('en', 'messages', 'site.greeting', 'After %name%');
        self::assertNotSame($broken, $this->private->live());
        self::assertSame(['en' => 'After %name%'], $this->overrideTexts());
        self::assertTrue($this->host->getUnitOfWork()->isScheduledForInsert($note), 'The host manager was never touched.');
        $dispatcher->dispatch(new FinishRequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST), KernelEvents::FINISH_REQUEST);
    }

    /** One connection: the host can commit its entity and the texts together, or roll both back. */
    public function testHostTransactionCoversTheBundleFlush(): void
    {
        try {
            $this->connection->transactional(function (): never {
                $this->host->persist(new HostNote('form'));
                $this->host->flush();
                $this->editor()->save('messages', 'site.greeting', ['en' => 'Together %name%']);

                throw new RuntimeException('validation failed later');
            });
        } catch (RuntimeException) {
            // The closure always throws: the transaction is rolled back.
        }
        self::assertSame(0, $this->rows('host_note'));
        self::assertSame([], $this->overrideTexts(), 'The bundle write was rolled back with the host\'s.');

        $this->host->clear();
        $this->connection->transactional(function (): void {
            $this->host->persist(new HostNote('form'));
            $this->host->flush();
            $this->editor()->save('messages', 'site.greeting', ['en' => 'Together %name%']);
        });
        self::assertSame(1, $this->rows('host_note'));
        self::assertSame(['en' => 'Together %name%'], $this->overrideTexts());
    }

    /** The outermost-only console guard applies to the private manager too: an inner command keeps the outer unit's. */
    public function testNestedConsoleCommandKeepsTheOuterUnitManager(): void
    {
        $dispatcher = $this->service('event_dispatcher', EventDispatcherInterface::class);

        $command   = new Command('test:outer');
        $input     = new ArrayInput([]);
        $output    = new NullOutput();
        $start     = new ConsoleCommandEvent($command, $input, $output);
        $terminate = new ConsoleTerminateEvent($command, $input, $output, 0);

        $dispatcher->dispatch($start, 'console.command');
        $this->overrides()->set('en', 'messages', 'site.greeting', 'outer %name%');
        $outer = $this->private->live();
        $dispatcher->dispatch($start, 'console.command');
        $dispatcher->dispatch($terminate, 'console.terminate');
        self::assertSame($outer, $this->private->live(), 'The inner command did not drop the outer unit manager.');
        $dispatcher->dispatch($terminate, 'console.terminate');
        self::assertFalse($this->private->isCreated(), 'The outermost terminate dropped it.');
    }

    public function testSourceSqlFiltersAreMirroredWithoutStaleParameters(): void
    {
        $sourceFilters = $this->host->getFilters();
        $sourceFilters->enable('tenant')->setParameter('tenant', 'acme');
        $this->overrideRepository()->findOne('en', 'messages', 'site.greeting');
        $filters = $this->private->live()->getFilters();
        self::assertTrue($filters->isEnabled('tenant'));
        self::assertSame("'acme'", $filters->getFilter('tenant')->getParameter('tenant'));

        // The source filter is re-created without the parameter: the old tenant must not survive.
        $sourceFilters->disable('tenant');
        $sourceFilters->enable('tenant');
        $filters = $this->private->live()->getFilters();
        self::assertTrue($filters->isEnabled('tenant'));
        try {
            $filters->getFilter('tenant')->getParameter('tenant');
            self::fail('The stale tenant parameter must not survive.');
        } catch (InvalidArgumentException) {
        }

        $sourceFilters->getFilter('tenant')->setParameter('tenant', 'globex');
        self::assertSame("'globex'", $this->private->live()->getFilters()->getFilter('tenant')->getParameter('tenant'));
        $sourceFilters->getFilter('tenant')->setParameterList('regions', ['eu', 'us']);
        self::assertSame("'eu','us'", $this->private->live()->getFilters()->getFilter('tenant')->getParameterList('regions'));

        $sourceFilters->disable('tenant');
        self::assertFalse($this->private->live()->getFilters()->isEnabled('tenant'), 'A filter the source disabled is disabled.');
    }

    /**
     * The pre-1.18 form bridge ("flush: false", then the application's own flush) keeps writing the staged rows,
     * after the application's commit, with a deprecation; the application manager is never flushed by the bundle.
     */
    #[IgnoreDeprecations]
    public function testDeprecatedFlushBridgeWritesStagedRowsAfterTheHostFlush(): void
    {
        $editor = $this->editor();
        $editor->save('messages', 'site.greeting', ['en' => 'Staged %name%'], flush: false);
        self::assertSame([], $this->overrideTexts(), 'Staged only.');

        $deprecations = [];
        // Test-only handler, restored in finally.
        // @phpstan-ignore frankenphp.worker.noSetErrorExceptionHandler
        set_error_handler(static function (int $level, string $message) use (&$deprecations): bool {
            $deprecations[] = $message;

            return true;
        }, E_USER_DEPRECATED);
        try {
            $this->host->persist(new HostNote('form'));
            $this->host->flush();
        } finally {
            restore_error_handler();
        }

        self::assertSame(['en' => 'Staged %name%'], $this->overrideTexts());
        self::assertSame(1, $this->rows('host_note'));
        self::assertCount(1, $deprecations);
        self::assertStringContainsString('TranslationOverrideEditor::flush()', $deprecations[0]);
        self::assertFalse($this->private->hasPendingWork());

        // Nothing staged: a host flush neither writes nor warns.
        $this->host->persist(new HostNote('other'));
        $this->host->flush();
        self::assertSame(2, $this->rows('host_note'));
    }

    /**
     * Audit ND-M1: when the bridge flush fails (a duplicate staged row), the application's flush still completes, its
     * unit of work is cleaned up, and a second flush does not write its rows twice.
     */
    #[IgnoreDeprecations]
    public function testFailingBridgeFlushNeverLeavesTheHostUnitOfWorkDirty(): void
    {
        $this->overrides()->set('en', 'messages', 'site.greeting', 'Hi %name%');
        $this->overrideRepository()->persist(new TranslationOverride('en', 'messages', 'site.greeting')); // duplicate key

        $note = new HostNote('form');
        $this->host->persist($note);
        $this->host->flush(); // the bridge flush fails inside postFlush: logged, not rethrown

        $unitOfWork = $this->host->getUnitOfWork();
        self::assertSame([], $unitOfWork->getScheduledEntityInsertions());
        self::assertSame([], $unitOfWork->getScheduledEntityUpdates());
        self::assertSame(1, $this->rows('host_note'));
        $this->host->flush();
        self::assertSame(1, $this->rows('host_note'), 'The second flush wrote nothing twice.');
        self::assertTrue($this->host->isOpen());
        self::assertSame(['en' => 'Hi %name%'], $this->overrideTexts());
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function service(string $id, string $class): object
    {
        $service = self::getContainer()->get($id);
        self::assertInstanceOf($class, $service);

        return $service;
    }

    private function overrides(): TranslationOverrides
    {
        return $this->service('test.pem.overrides', TranslationOverrides::class);
    }

    private function editor(): TranslationOverrideEditor
    {
        return $this->service('test.pem.editor', TranslationOverrideEditor::class);
    }

    private function overrideRepository(): TranslationOverrideRepository
    {
        return $this->service('test.pem.override_repository', TranslationOverrideRepository::class);
    }

    private function logRepository(): MissingTranslationLogRepository
    {
        return $this->service('test.pem.log_repository', MissingTranslationLogRepository::class);
    }

    private function rows(string $table): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM ' . $table);
    }

    private function logId(string $messageId): int
    {
        return (int) $this->connection->fetchOne('SELECT id FROM nowo_translation_missing_log WHERE message_id = ?', [$messageId]);
    }

    /**
     * @return array<string, string> locale => text, for site.greeting / site.topline.hours rows (ordered by id)
     */
    private function overrideTexts(): array
    {
        $texts = [];
        foreach ($this->connection->fetchAllAssociative(sprintf('SELECT locale, value FROM %s ORDER BY id', 'nowo_translation_override')) as $row) {
            $texts[(string) $row['locale']] = (string) $row['value'];
        }

        return $texts;
    }

    /**
     * @return array<string, string> message id => status
     */
    private function logStatuses(): array
    {
        $statuses = [];
        foreach ($this->connection->fetchAllAssociative('SELECT message_id, status FROM nowo_translation_missing_log ORDER BY id') as $row) {
            $statuses[(string) $row['message_id']] = (string) $row['status'];
        }

        return $statuses;
    }
}
