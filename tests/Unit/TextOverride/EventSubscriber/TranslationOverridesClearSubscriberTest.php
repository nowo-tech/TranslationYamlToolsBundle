<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Tests\Unit\TextOverride\EventSubscriber;

use Nowo\TranslationYamlToolsBundle\Tests\Support\BoundaryDriver;
use Nowo\TranslationYamlToolsBundle\TextOverride\EventSubscriber\TranslationOverridesClearSubscriber;
use PHPUnit\Framework\TestCase;
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

final class TranslationOverridesClearSubscriberTest extends TestCase
{
    private int $resets = 0;

    public function testSubscribesToEveryUnitOfWorkBoundary(): void
    {
        $events = TranslationOverridesClearSubscriber::getSubscribedEvents();

        self::assertSame(['onRequest', PHP_INT_MAX], $events[KernelEvents::REQUEST]);
        self::assertSame(['onFinishRequest', PHP_INT_MAX], $events[KernelEvents::FINISH_REQUEST]);
        self::assertSame('clearState', $events[KernelEvents::TERMINATE]);
        self::assertSame(['onMessageReceived', PHP_INT_MAX], $events[WorkerMessageReceivedEvent::class]);
        self::assertSame([['onMessageEnded', PHP_INT_MAX], ['clearState', -4096]], $events[WorkerMessageHandledEvent::class]);
        self::assertSame([['onMessageEnded', PHP_INT_MAX], ['clearState', -4096]], $events[WorkerMessageFailedEvent::class]);
        self::assertSame(['onMessageEnded', PHP_INT_MAX], $events[WorkerMessageSkipEvent::class]);
        self::assertSame(['onWorkerStopped', PHP_INT_MAX], $events[WorkerStoppedEvent::class]);
        self::assertSame(['onConsoleCommand', PHP_INT_MAX], $events[ConsoleEvents::COMMAND]);
        self::assertSame(['onConsoleError', PHP_INT_MAX], $events[ConsoleEvents::ERROR]);
        self::assertSame([['onConsoleCommandEnded', PHP_INT_MAX], ['onConsoleTerminate', -4096]], $events[ConsoleEvents::TERMINATE]);
    }

    public function testClearsOnEveryMainRequestAndTerminate(): void
    {
        $units = $this->driver();

        $units->request(HttpKernelInterface::SUB_REQUEST);
        self::assertSame(0, $this->resets);
        $units->request();
        $units->request();
        self::assertSame(2, $this->resets, 'Every main request starts with a fresh memo.');
        $units->finishRequest(HttpKernelInterface::SUB_REQUEST);
        $units->finishRequest();
        $units->finishRequest();
        $units->terminate();
        self::assertSame(3, $this->resets);
    }

    public function testNestedCommandsClearOnlyAtTheOutermostBoundaries(): void
    {
        $units = $this->driver();

        $units->commandStarts();
        self::assertSame(1, $this->resets);
        $units->commandStarts();
        $units->commandEnds();
        self::assertSame(1, $this->resets, 'A nested command must not clear the outer command.');
        $units->commandEnds();
        self::assertSame(2, $this->resets);

        // A terminate of a run that never started changes nothing (audit NM: never a blind decrement).
        $units->commandEnds();
        self::assertSame(2, $this->resets);
        $units->commandStarts();
        self::assertSame(3, $this->resets);
    }

    public function testCommandsInsideAMessageDoNotClear(): void
    {
        $units = $this->driver();

        $units->received();
        self::assertSame(1, $this->resets);
        $units->commandStarts();
        $units->commandEnds();
        self::assertSame(1, $this->resets, 'A command run from a handler must not clear the message.');
        $units->handled();
        self::assertSame(2, $this->resets);

        // A skipped message (messenger:failed:retry) or a stopped worker ends the in-flight state.
        $units->received();
        $units->skipped();
        self::assertSame(3, $this->resets);
        $units->commandStarts();
        self::assertSame(4, $this->resets);
        $units->commandEnds();
        $units->received();
        $units->failed();
        $units->received();
        $units->workerStopped();
        $units->commandStarts();
        self::assertSame(9, $this->resets);
    }

    /**
     * Audit NL2-v: a failing run whose error listener threw is dropped at the next unit start.
     */
    public function testAFailingRunWithoutTerminateIsDroppedAtTheNextCommand(): void
    {
        $units = $this->driver();

        $units->commandStarts();
        $units->commandFailsWithoutTerminate();
        $units->commandStarts();
        self::assertSame(2, $this->resets, 'The next command is the outermost unit again.');
        $units->commandEnds();
        self::assertSame(3, $this->resets);
    }

    private function driver(): BoundaryDriver
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
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new TranslationOverridesClearSubscriber([$counter]));

        return new BoundaryDriver($dispatcher, $this->createStub(HttpKernelInterface::class));
    }
}
