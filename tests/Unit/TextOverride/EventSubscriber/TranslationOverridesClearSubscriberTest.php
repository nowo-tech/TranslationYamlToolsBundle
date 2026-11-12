<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Tests\Unit\TextOverride\EventSubscriber;

use Nowo\TranslationYamlToolsBundle\TextOverride\EventSubscriber\TranslationOverridesClearSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\HttpFoundation\Request;
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

final class TranslationOverridesClearSubscriberTest extends TestCase
{
    private int $resets = 0;

    public function testSubscribesToEveryUnitOfWorkBoundary(): void
    {
        $events = TranslationOverridesClearSubscriber::getSubscribedEvents();

        self::assertSame(['onRequest', 4096], $events[KernelEvents::REQUEST]);
        self::assertSame('onTerminate', $events[KernelEvents::TERMINATE]);
        self::assertSame(['onMessageReceived', 4096], $events[WorkerMessageReceivedEvent::class]);
        self::assertSame(['onMessageFinished', -4096], $events[WorkerMessageHandledEvent::class]);
        self::assertSame(['onMessageFinished', -4096], $events[WorkerMessageFailedEvent::class]);
        self::assertSame(['onWorkerStopped', -4096], $events[WorkerStoppedEvent::class]);
        self::assertSame(['onConsoleCommand', PHP_INT_MAX], $events[ConsoleEvents::COMMAND]);
        self::assertSame(['onConsoleError', PHP_INT_MAX], $events[ConsoleEvents::ERROR]);
        self::assertSame([['onConsoleCommandEnded', PHP_INT_MAX], ['onConsoleTerminate', -4096]], $events[ConsoleEvents::TERMINATE]);
        self::assertSame(['onWorkerStopped', -4096], $events['Symfony\\Component\\Messenger\\Event\\WorkerMessageSkipEvent']);
    }

    public function testClearsOnMainRequestOnly(): void
    {
        $subscriber = $this->subscriber();
        $kernel     = $this->createStub(HttpKernelInterface::class);

        $subscriber->onRequest(new RequestEvent($kernel, new Request(), HttpKernelInterface::SUB_REQUEST));
        self::assertSame(0, $this->resets);

        $subscriber->onRequest(new RequestEvent($kernel, new Request(), HttpKernelInterface::MAIN_REQUEST));
        self::assertSame(1, $this->resets);

        $subscriber->clearState();
        self::assertSame(2, $this->resets);
    }

    public function testNestedCommandsClearOnlyAtTheOutermostBoundaries(): void
    {
        $subscriber = $this->subscriber();

        $this->commandStarts($subscriber);
        self::assertSame(1, $this->resets);
        $this->commandStarts($subscriber);
        $this->commandEnds($subscriber);
        self::assertSame(1, $this->resets, 'A nested command must not clear the outer command.');
        $this->commandEnds($subscriber);
        self::assertSame(2, $this->resets);

        // A terminate of a command that never started changes nothing (audit NM: never a blind decrement).
        $this->commandEnds($subscriber);
        self::assertSame(2, $this->resets);
        $this->commandStarts($subscriber);
        self::assertSame(3, $this->resets);
    }

    public function testCommandsInsideAMessageDoNotClear(): void
    {
        $subscriber = $this->subscriber();

        $subscriber->onMessageReceived();
        self::assertSame(1, $this->resets);
        $this->commandStarts($subscriber);
        $this->commandEnds($subscriber);
        self::assertSame(1, $this->resets, 'A command run from a handler must not clear the message.');
        $subscriber->onMessageFinished();
        self::assertSame(2, $this->resets);

        // A skipped message (no handled / failed event): the stopped worker ends the in-flight state.
        $subscriber->onMessageReceived();
        $subscriber->onWorkerStopped();
        self::assertSame(3, $this->resets);
        $this->commandStarts($subscriber);
        self::assertSame(4, $this->resets);
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
        self::assertSame(2, $this->resets);
        $this->commandStarts($subscriber);
        self::assertSame(3, $this->resets, 'The next outermost command clears again.');
        $this->commandEnds($subscriber);
        self::assertSame(4, $this->resets);

        $subscriber->onMessageReceived();
        $this->commandStarts($subscriber);
        $this->running = [];
        $subscriber->onWorkerStopped();
        $subscriber->onWorkerStopped();
        self::assertSame(5, $this->resets);
        $this->commandStarts($subscriber);
        self::assertSame(6, $this->resets);
    }

    private function subscriber(): TranslationOverridesClearSubscriber
    {
        $this->resets = 0;
        $counter      = new class($this->resets) implements ResetInterface {
            public function __construct(
                private int &$resets,
            ) {
            }

            public function reset(): void
            {
                ++$this->resets;
            }
        };

        return new TranslationOverridesClearSubscriber([$counter]);
    }

    /** @var list<Command> */
    private array $running = [];

    private function commandStarts(TranslationOverridesClearSubscriber $subscriber): void
    {
        $command         = new Command('test:command-' . count($this->running));
        $this->running[] = $command;
        $subscriber->onConsoleCommand(new ConsoleCommandEvent($command, new ArrayInput([]), new NullOutput()));
    }

    /** Ends the innermost running command (an unknown one when none runs), as Symfony dispatches it. */
    private function commandEnds(TranslationOverridesClearSubscriber $subscriber): void
    {
        $command = array_pop($this->running) ?? new Command('test:unknown');
        $event   = new ConsoleTerminateEvent($command, new ArrayInput([]), new NullOutput(), 0);
        $subscriber->onConsoleCommandEnded($event);
        $subscriber->onConsoleTerminate($event);
    }
}
