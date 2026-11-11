<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Tests\Unit\TextOverride\EventSubscriber;

use Nowo\TranslationYamlToolsBundle\TextOverride\EventSubscriber\TranslationOverridesClearSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Event\WorkerStoppedEvent;
use Symfony\Contracts\Service\ResetInterface;

final class TranslationOverridesClearSubscriberTest extends TestCase
{
    private int $resets = 0;

    public function testSubscribesToEveryUnitOfWorkBoundary(): void
    {
        $events = TranslationOverridesClearSubscriber::getSubscribedEvents();

        self::assertSame(['onRequest', 4096], $events[KernelEvents::REQUEST]);
        self::assertSame('clearState', $events[KernelEvents::TERMINATE]);
        self::assertSame(['onMessageReceived', 4096], $events[WorkerMessageReceivedEvent::class]);
        self::assertSame(['onMessageFinished', -4096], $events[WorkerMessageHandledEvent::class]);
        self::assertSame(['onMessageFinished', -4096], $events[WorkerMessageFailedEvent::class]);
        self::assertSame(['onWorkerStopped', -4096], $events[WorkerStoppedEvent::class]);
        self::assertSame(['onConsoleCommand', 4096], $events[ConsoleEvents::COMMAND]);
        self::assertSame(['onConsoleTerminate', -4096], $events[ConsoleEvents::TERMINATE]);
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

        $subscriber->onConsoleCommand();
        self::assertSame(1, $this->resets);
        $subscriber->onConsoleCommand();
        $subscriber->onConsoleTerminate();
        self::assertSame(1, $this->resets, 'A nested command must not clear the outer command.');
        $subscriber->onConsoleTerminate();
        self::assertSame(2, $this->resets);

        // A stray terminate never drives the depth below zero.
        $subscriber->onConsoleTerminate();
        self::assertSame(3, $this->resets);
        $subscriber->onConsoleCommand();
        self::assertSame(4, $this->resets);
    }

    public function testCommandsInsideAMessageDoNotClear(): void
    {
        $subscriber = $this->subscriber();

        $subscriber->onMessageReceived();
        self::assertSame(1, $this->resets);
        $subscriber->onConsoleCommand();
        $subscriber->onConsoleTerminate();
        self::assertSame(1, $this->resets, 'A command run from a handler must not clear the message.');
        $subscriber->onMessageFinished();
        self::assertSame(2, $this->resets);

        // A skipped message (no handled / failed event): the stopped worker ends the in-flight state.
        $subscriber->onMessageReceived();
        $subscriber->onWorkerStopped();
        self::assertSame(3, $this->resets);
        $subscriber->onConsoleCommand();
        self::assertSame(4, $this->resets);
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
}
