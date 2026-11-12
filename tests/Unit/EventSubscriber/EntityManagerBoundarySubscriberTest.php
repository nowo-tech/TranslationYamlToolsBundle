<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Tests\Unit\EventSubscriber;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\UnitOfWork;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\TranslationYamlToolsBundle\EventSubscriber\EntityManagerBoundarySubscriber;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use stdClass;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\FinishRequestEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Event\WorkerStoppedEvent;
use Symfony\Contracts\Service\ResetInterface;

use function array_pop;
use function count;

use const PHP_INT_MAX;

#[CoversClass(EntityManagerBoundarySubscriber::class)]
final class EntityManagerBoundarySubscriberTest extends TestCase
{
    private int $clears = 0;

    public function testSubscribesToEveryUnitOfWorkBoundary(): void
    {
        $events = EntityManagerBoundarySubscriber::getSubscribedEvents();

        self::assertSame(['onRequest', 4096], $events[KernelEvents::REQUEST]);
        self::assertSame(['onFinishRequest', -4096], $events[KernelEvents::FINISH_REQUEST]);
        self::assertSame(['onTerminate', -4096], $events[KernelEvents::TERMINATE]);
        self::assertSame(['onMessageReceived', 4096], $events[WorkerMessageReceivedEvent::class]);
        self::assertSame(['onMessageFinished', -4096], $events[WorkerMessageHandledEvent::class]);
        self::assertSame(['onMessageFinished', -4096], $events[WorkerMessageFailedEvent::class]);
        self::assertSame(['onWorkerStopped', -4096], $events[WorkerStoppedEvent::class]);
        self::assertSame(['onConsoleCommand', PHP_INT_MAX], $events[ConsoleEvents::COMMAND]);
        self::assertSame(['onConsoleError', PHP_INT_MAX], $events[ConsoleEvents::ERROR]);
        self::assertSame([['onConsoleCommandEnded', PHP_INT_MAX], ['onConsoleTerminate', -4096]], $events[ConsoleEvents::TERMINATE]);
        self::assertSame(['onWorkerStopped', -4096], $events['Symfony\\Component\\Messenger\\Event\\WorkerMessageSkipEvent']);
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
        $subscriber = $this->subscriber();

        $subscriber->onRequest($this->request(HttpKernelInterface::SUB_REQUEST));
        self::assertSame(0, $this->clears);
        $subscriber->onRequest($this->request(HttpKernelInterface::MAIN_REQUEST));
        self::assertSame(1, $this->clears);
        $subscriber->onFinishRequest($this->finish(HttpKernelInterface::SUB_REQUEST));
        $subscriber->onFinishRequest($this->finish(HttpKernelInterface::MAIN_REQUEST));
        $subscriber->onTerminate();
        self::assertSame(2, $this->clears);
    }

    /**
     * A command run in-process from a controller does not clear the request's unit of work.
     */
    public function testCommandsInsideAMainRequestDoNotClear(): void
    {
        $subscriber = $this->subscriber();

        $subscriber->onRequest($this->request(HttpKernelInterface::MAIN_REQUEST));
        self::assertSame(1, $this->clears);
        $this->commandStarts($subscriber);
        $this->commandEnds($subscriber);
        self::assertSame(1, $this->clears, 'A command run from a controller must not clear the request.');
        // A sub-request's finish does not end the main request.
        $subscriber->onFinishRequest($this->finish(HttpKernelInterface::SUB_REQUEST));
        $this->commandStarts($subscriber);
        $this->commandEnds($subscriber);
        self::assertSame(1, $this->clears);
        $subscriber->onFinishRequest($this->finish(HttpKernelInterface::MAIN_REQUEST));
        $this->commandStarts($subscriber);
        self::assertSame(2, $this->clears);
    }

    /**
     * An in-process HttpKernel::handle() from a command or a message handler does not clear the unit that runs it.
     */
    public function testRequestsInsideACommandOrAMessageDoNotClear(): void
    {
        $subscriber = $this->subscriber();

        $this->commandStarts($subscriber);
        self::assertSame(1, $this->clears);
        $subscriber->onRequest($this->request(HttpKernelInterface::MAIN_REQUEST));
        $subscriber->onFinishRequest($this->finish(HttpKernelInterface::MAIN_REQUEST));
        $subscriber->onTerminate();
        self::assertSame(1, $this->clears, 'A request handled inside a command must not clear it.');
        $this->commandEnds($subscriber);
        self::assertSame(2, $this->clears);

        $subscriber->onMessageReceived();
        self::assertSame(3, $this->clears);
        $subscriber->onRequest($this->request(HttpKernelInterface::MAIN_REQUEST));
        $subscriber->onFinishRequest($this->finish(HttpKernelInterface::MAIN_REQUEST));
        $subscriber->onTerminate();
        self::assertSame(3, $this->clears, 'A request handled inside a message must not clear it.');
        $subscriber->onMessageFinished();
        self::assertSame(4, $this->clears);
    }

    public function testNestedCommandsClearOnlyAtTheOutermostBoundaries(): void
    {
        $subscriber = $this->subscriber();

        $this->commandStarts($subscriber);
        self::assertSame(1, $this->clears);
        $this->commandStarts($subscriber);
        $this->commandEnds($subscriber);
        self::assertSame(1, $this->clears, 'A nested command must not clear the outer command.');
        $this->commandEnds($subscriber);
        self::assertSame(2, $this->clears);

        // A terminate of a command that never started changes nothing (audit NM: never a blind decrement).
        $this->commandEnds($subscriber);
        self::assertSame(2, $this->clears);
        $this->commandStarts($subscriber);
        self::assertSame(3, $this->clears);
    }

    public function testCommandsInsideAMessageDoNotClear(): void
    {
        $subscriber = $this->subscriber();

        $subscriber->onMessageReceived();
        self::assertSame(1, $this->clears);
        $this->commandStarts($subscriber);
        $this->commandEnds($subscriber);
        self::assertSame(1, $this->clears, 'A command run from a handler must not clear the message.');
        $subscriber->onMessageFinished();
        self::assertSame(2, $this->clears);

        // A skipped message (no handled / failed event): the stopped worker ends the in-flight state.
        $subscriber->onMessageReceived();
        $subscriber->onWorkerStopped();
        self::assertSame(3, $this->clears);
        $this->commandStarts($subscriber);
        self::assertSame(4, $this->clears);
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

    public function testThePrivateManagerIsResetAtEveryClear(): void
    {
        $private = $this->createMock(ResetInterface::class);
        $private->expects(self::exactly(2))->method('reset');

        $subscriber = new EntityManagerBoundarySubscriber([$private]);
        $subscriber->onMessageReceived();
        $this->commandStarts($subscriber);
        $subscriber->onMessageFinished();
    }

    private function request(int $type): RequestEvent
    {
        return new RequestEvent($this->createStub(HttpKernelInterface::class), new Request(), $type);
    }

    private function finish(int $type): FinishRequestEvent
    {
        return new FinishRequestEvent($this->createStub(HttpKernelInterface::class), new Request(), $type);
    }

    /**
     * Audit NM: a command run by a message handler whose console.terminate never ran is dropped when the message ends
     * (handled, skipped or worker stopped), so the next outermost command still clears.
     */
    public function testAMessageDropsACommandThatNeverTerminated(): void
    {
        $subscriber = $this->subscriber();

        $subscriber->onMessageReceived();
        $this->commandStarts($subscriber);
        $this->running = [];
        $subscriber->onMessageFinished();
        self::assertSame(2, $this->clears);
        $this->commandStarts($subscriber);
        self::assertSame(3, $this->clears, 'The next outermost command clears again.');
        $this->commandEnds($subscriber);
        self::assertSame(4, $this->clears);

        $subscriber->onMessageReceived();
        $this->commandStarts($subscriber);
        $this->running = [];
        $subscriber->onWorkerStopped();
        $subscriber->onWorkerStopped();
        self::assertSame(5, $this->clears);
        $this->commandStarts($subscriber);
        self::assertSame(6, $this->clears);
    }

    private function subscriber(): EntityManagerBoundarySubscriber
    {
        $this->clears = 0;
        $manager      = $this->createStub(EntityManagerInterface::class);
        $manager->method('isOpen')->willReturn(true);
        $manager->method('clear')->willReturnCallback(function (): void {
            ++$this->clears;
        });

        return new EntityManagerBoundarySubscriber([], [$manager], [], 'translations');
    }

    /** @var list<Command> */
    private array $running = [];

    private function commandStarts(EntityManagerBoundarySubscriber $subscriber): void
    {
        $command         = new Command('test:command-' . count($this->running));
        $this->running[] = $command;
        $subscriber->onConsoleCommand(new ConsoleCommandEvent($command, new ArrayInput([]), new NullOutput()));
    }

    /** Ends the innermost running command (an unknown one when none runs), as Symfony dispatches it. */
    private function commandEnds(EntityManagerBoundarySubscriber $subscriber): void
    {
        $command = array_pop($this->running) ?? new Command('test:unknown');
        $event   = new ConsoleTerminateEvent($command, new ArrayInput([]), new NullOutput(), 0);
        $subscriber->onConsoleCommandEnded($event);
        $subscriber->onConsoleTerminate($event);
    }
}
