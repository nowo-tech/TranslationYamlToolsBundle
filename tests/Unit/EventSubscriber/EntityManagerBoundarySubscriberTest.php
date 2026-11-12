<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Tests\Unit\EventSubscriber;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\UnitOfWork;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\TranslationYamlToolsBundle\EventSubscriber\EntityManagerBoundarySubscriber;
use Nowo\TranslationYamlToolsBundle\EventSubscriber\UnitOfWorkFrames;
use Nowo\TranslationYamlToolsBundle\Tests\Support\BoundaryDriver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use stdClass;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageSkipEvent;
use Symfony\Component\Messenger\Event\WorkerStoppedEvent;
use Symfony\Contracts\Service\ResetInterface;

use const PHP_INT_MAX;

#[CoversClass(EntityManagerBoundarySubscriber::class)]
#[CoversClass(UnitOfWorkFrames::class)]
final class EntityManagerBoundarySubscriberTest extends TestCase
{
    private int $clears = 0;

    public function testSubscribesToEveryUnitOfWorkBoundary(): void
    {
        $events = EntityManagerBoundarySubscriber::getSubscribedEvents();

        self::assertSame(['onRequest', PHP_INT_MAX], $events[KernelEvents::REQUEST]);
        self::assertSame(['onFinishRequest', PHP_INT_MAX], $events[KernelEvents::FINISH_REQUEST]);
        self::assertSame([['onRequestBoundaryEnded', PHP_INT_MAX], ['onTerminate', -4096]], $events[KernelEvents::TERMINATE]);
        self::assertSame(['onMessageReceived', PHP_INT_MAX], $events[WorkerMessageReceivedEvent::class]);
        self::assertSame([['onMessageEnded', PHP_INT_MAX], ['onMessageFinished', -4096]], $events[WorkerMessageHandledEvent::class]);
        self::assertSame([['onMessageEnded', PHP_INT_MAX], ['onMessageFinished', -4096]], $events[WorkerMessageFailedEvent::class]);
        self::assertSame(['onMessageEnded', PHP_INT_MAX], $events[WorkerMessageSkipEvent::class]);
        self::assertSame(['onWorkerStopped', PHP_INT_MAX], $events[WorkerStoppedEvent::class]);
        self::assertSame(['onConsoleCommand', PHP_INT_MAX], $events[ConsoleEvents::COMMAND]);
        self::assertSame(['onConsoleError', PHP_INT_MAX], $events[ConsoleEvents::ERROR]);
        self::assertSame([['onConsoleCommandEnded', PHP_INT_MAX], ['onConsoleTerminate', -4096]], $events[ConsoleEvents::TERMINATE]);
    }

    public function testWithoutABundleManagerNothingIsTouched(): void
    {
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->expects(self::never())->method(self::anything());

        (new EntityManagerBoundarySubscriber([], [], [$registry]))->clearState();
        (new EntityManagerBoundarySubscriber())->clearState();
        $this->addToAssertionCount(1);
    }

    public function testClearsAtTheMainRequestStartAndAtTerminateOnly(): void
    {
        $units = $this->driver();

        $units->request(HttpKernelInterface::SUB_REQUEST);
        self::assertSame(0, $this->clears);
        $units->request();
        self::assertSame(1, $this->clears);
        $units->finishRequest(HttpKernelInterface::SUB_REQUEST);
        $units->finishRequest();
        $units->finishRequest();
        $units->terminate();
        self::assertSame(2, $this->clears);
    }

    /**
     * Audit NL2-v: a main request handled in-process inside another one does not end it early.
     */
    public function testANestedMainRequestDoesNotClearTheOuterOne(): void
    {
        $units = $this->driver();

        $units->request();
        // In-process http_kernel->handle() (no terminate(): only the front controller terminates the outer request).
        $units->request();
        $units->finishRequest();
        self::assertSame(1, $this->clears, 'The nested main request neither starts nor ends a unit.');
        $units->commandStarts();
        self::assertSame(1, $this->clears, 'The outer request is still in flight.');
        $units->commandEnds();
        $units->finishRequest();
        $units->terminate();
        self::assertSame(2, $this->clears);
    }

    /**
     * A command run in-process from a controller does not clear the request's unit of work.
     */
    public function testCommandsInsideAMainRequestDoNotClear(): void
    {
        $units = $this->driver();

        $units->request();
        self::assertSame(1, $this->clears);
        $units->commandStarts();
        $units->commandEnds();
        self::assertSame(1, $this->clears, 'A command run from a controller must not clear the request.');
        $units->finishRequest();
        $units->commandStarts();
        self::assertSame(2, $this->clears);
    }

    /**
     * An in-process HttpKernel::handle() from a command or a message handler does not clear the unit that runs it.
     */
    public function testRequestsInsideACommandOrAMessageDoNotClear(): void
    {
        $units = $this->driver();

        $units->commandStarts();
        self::assertSame(1, $this->clears);
        $units->request();
        $units->finishRequest();
        $units->terminate();
        self::assertSame(1, $this->clears, 'A request handled inside a command must not clear it.');
        $units->commandEnds();
        self::assertSame(2, $this->clears);

        $units->received();
        self::assertSame(3, $this->clears);
        $units->request();
        $units->finishRequest();
        $units->terminate();
        self::assertSame(3, $this->clears, 'A request handled inside a message must not clear it.');
        $units->handled();
        self::assertSame(4, $this->clears);
    }

    public function testNestedCommandsClearOnlyAtTheOutermostBoundaries(): void
    {
        $units = $this->driver();

        $units->commandStarts();
        self::assertSame(1, $this->clears);
        $units->commandStarts();
        $units->commandEnds();
        self::assertSame(1, $this->clears, 'A nested command must not clear the outer command.');
        $units->commandEnds();
        self::assertSame(2, $this->clears);

        // A terminate of a run that never started changes nothing (audit NM: never a blind decrement).
        $units->commandEnds();
        self::assertSame(2, $this->clears);
        $units->commandStarts();
        self::assertSame(3, $this->clears);
    }

    /**
     * Audit NL2-v: a command that runs itself in-process is a separate frame per run (same command, other input).
     */
    public function testACommandRunningItselfIsTrackedPerRun(): void
    {
        $units   = $this->driver();
        $command = new Command('app:recursive');

        $units->commandStarts($command);
        $units->commandStarts($command);
        $units->commandEnds();
        self::assertSame(1, $this->clears, 'The inner run must not end the outer one.');
        $units->commandEnds();
        self::assertSame(2, $this->clears);
    }

    /**
     * Audit NL2-v: a failing run whose error listener threw is dropped at the next unit start, with the state it saved.
     */
    public function testAFailingRunWithoutTerminateIsDroppedAtTheNextUnit(): void
    {
        $units = $this->driver();

        // Top-level command: dropped at the next main request.
        $units->commandStarts();
        $units->commandFailsWithoutTerminate();
        $units->request();
        self::assertSame(2, $this->clears, 'The next main request is the outermost unit again.');
        $units->finishRequest();
        $units->terminate();
        self::assertSame(3, $this->clears);

        // Inside a message: dropped at the next command, which is the outermost unit again.
        $units->received();
        $units->commandStarts();
        $units->commandFailsWithoutTerminate();
        $units->handled();
        $units->commandStarts();
        self::assertSame(6, $this->clears);
        $units->commandEnds();

        // At the next message and at a worker stop too.
        $units->commandStarts();
        $units->commandFailsWithoutTerminate();
        $units->received();
        $units->handled();
        $units->commandStarts();
        $units->commandFailsWithoutTerminate();
        $units->workerStopped();
        $units->request();
        self::assertSame(12, $this->clears);

        // An error without a running command changes nothing.
        $units->commandFailsWithoutTerminate();
        $units->finishRequest();
        $units->terminate();
        self::assertSame(13, $this->clears);
    }

    /**
     * Audit NL2-v: a message that never ended inside a command (a received listener threw) ends with the command.
     */
    public function testAMessageThatNeverEndedInsideACommandEndsWithIt(): void
    {
        $units = $this->driver();

        $units->commandStarts();
        $units->received();
        $units->commandEnds();
        self::assertSame(3, $this->clears);
        $units->request();
        self::assertSame(4, $this->clears, 'No message is left in flight.');
    }

    public function testCommandsInsideAMessageDoNotClear(): void
    {
        $units = $this->driver();

        $units->received();
        self::assertSame(1, $this->clears);
        $units->commandStarts();
        $units->commandEnds();
        self::assertSame(1, $this->clears, 'A command run from a handler must not clear the message.');
        $units->failed();
        self::assertSame(2, $this->clears);

        // A skipped message (messenger:failed:retry) or a stopped worker ends the in-flight state without clearing.
        $units->received();
        $units->skipped();
        self::assertSame(3, $this->clears);
        $units->commandStarts();
        self::assertSame(4, $this->clears);
        $units->commandEnds();
        $units->received();
        $units->workerStopped();
        $units->commandStarts();
        self::assertSame(7, $this->clears);
    }

    /**
     * An open manager is always cleared, whatever is pending (without a logger it is never inspected).
     */
    public function testAnOpenManagerIsAlwaysCleared(): void
    {
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->method('isOpen')->willReturn(true);
        $manager->expects(self::never())->method('getUnitOfWork');
        $manager->expects(self::once())->method('clear');
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->expects(self::never())->method('resetManager');

        (new EntityManagerBoundarySubscriber([], [$manager], [$registry], 'translations'))->clearState();
    }

    public function testAClosedManagerIsRecreatedByName(): void
    {
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->method('isOpen')->willReturn(false);
        $manager->expects(self::never())->method('clear');
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->expects(self::once())->method('resetManager')->with('translations');

        (new EntityManagerBoundarySubscriber([], [$manager], [$registry], 'translations'))->clearState();
        // Without a name there is nothing the bundle may reset.
        (new EntityManagerBoundarySubscriber([], [$manager], [$registry]))->clearState();
    }

    /** Audit ND-L2: clearing a dedicated manager with unflushed work logs a warning. */
    public function testClearingPendingWorkOfTheDedicatedManagerIsLogged(): void
    {
        $unitOfWork = $this->createMock(UnitOfWork::class);
        $unitOfWork->method('getScheduledEntityInsertions')->willReturn([new stdClass(), new stdClass()]);
        $unitOfWork->method('getScheduledEntityUpdates')->willReturn([]);
        $unitOfWork->method('getScheduledEntityDeletions')->willReturn([new stdClass()]);
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->method('isOpen')->willReturn(true);
        $manager->method('getUnitOfWork')->willReturn($unitOfWork);
        $manager->expects(self::once())->method('clear');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with(self::stringContains('Discarding'), ['count' => 3, 'manager' => 'translations']);

        (new EntityManagerBoundarySubscriber([], [$manager], [], 'translations', $logger))->clearState();
    }

    /** Audit NX: a unit of work that throws while computing its change sets never stops the clear. */
    public function testAThrowingChangeSetComputationStillClearsTheDedicatedManager(): void
    {
        $unitOfWork = $this->createMock(UnitOfWork::class);
        $unitOfWork->method('computeChangeSets')->willThrowException(new RuntimeException('secret value'));
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->method('isOpen')->willReturn(true);
        $manager->method('getUnitOfWork')->willReturn($unitOfWork);
        $manager->expects(self::once())->method('clear');
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->expects(self::never())->method('resetManager');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with(self::stringContains('Could not inspect'), ['manager' => 'translations', 'exception_class' => RuntimeException::class]);

        (new EntityManagerBoundarySubscriber([], [$manager], [$registry], 'translations', $logger))->clearState();
    }

    /** Audit NX: a clear() that throws recreates the dedicated manager by name instead of throwing at the boundary. */
    public function testAThrowingClearRecreatesTheDedicatedManager(): void
    {
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->method('isOpen')->willReturn(true);
        $manager->method('clear')->willThrowException(new RuntimeException('secret value'));
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->expects(self::once())->method('resetManager')->with('translations');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with(self::stringContains('Could not clear'), ['manager' => 'translations', 'exception_class' => RuntimeException::class]);

        (new EntityManagerBoundarySubscriber([], [$manager], [$registry], 'translations', $logger))->clearState();
    }

    public function testThePrivateManagerIsResetAtEveryClear(): void
    {
        $private = $this->createMock(ResetInterface::class);
        $private->expects(self::exactly(2))->method('reset');

        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new EntityManagerBoundarySubscriber([$private]));
        $units = new BoundaryDriver($dispatcher, $this->createStub(HttpKernelInterface::class));
        $units->received();
        $units->commandStarts();
        $units->handled();
    }

    private function driver(): BoundaryDriver
    {
        $this->clears = 0;
        $manager      = $this->createStub(EntityManagerInterface::class);
        $manager->method('isOpen')->willReturn(true);
        $manager->method('clear')->willReturnCallback(function (): void {
            ++$this->clears;
        });
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new EntityManagerBoundarySubscriber([], [$manager], [], 'translations'));

        return new BoundaryDriver($dispatcher, $this->createStub(HttpKernelInterface::class));
    }
}
