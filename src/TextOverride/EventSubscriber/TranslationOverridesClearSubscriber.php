<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\TextOverride\EventSubscriber;

use Nowo\TranslationYamlToolsBundle\EventSubscriber\UnitOfWorkFrames;
use Nowo\TranslationYamlToolsBundle\TextOverride\TranslationOverrides;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleErrorEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\FinishRequestEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\Service\ResetInterface;

use const PHP_INT_MAX;

/**
 * Drops the per-request override map memo ({@see TranslationOverrides::reset()}) at every unit-of-work boundary, so a
 * long-lived worker reads the shared cache / database again and sees overrides edited by another worker or process
 * (REQ-RUNTIME-002 rule 1). {@see TranslationOverrides} stays tagged `kernel.reset` too; this subscriber keeps it
 * correct where the services resetter does not run: a host that removes or bypasses `services_resetter` (or drives
 * `http_kernel` directly), Messenger consumers started with `--no-reset`, and long-running console commands.
 *
 * Boundaries (every unit-of-work state change at PHP_INT_MAX, through {@see UnitOfWorkFrames}, 1.18.1 audit NM):
 * - HTTP: the start of every main request (PHP_INT_MAX, before controllers and listeners translate) and
 *   `kernel.terminate`. Sub-requests (fragments, ESI) share their main request's memo;
 * - Messenger: when a message is received (PHP_INT_MAX) and after it is handled or failed (priority -4096). A skipped
 *   message (`WorkerMessageSkipEvent`, dispatched by `messenger:failed:retry` only) or a stopped worker ends the
 *   in-flight message;
 * - console: when the OUTERMOST run starts (PHP_INT_MAX) and terminates (priority -4096), outside a Messenger message
 *   and a main request. A command run in-process from another command, a message handler or a controller
 *   (`Application::doRun()`) does not clear the outer unit's memo. Each run is a frame keyed by its command and its
 *   input, removed by its own `console.terminate`; a run whose `console.error` listener threw (no terminate follows)
 *   is dropped at the next request, message, command or worker stop, so a throwing or propagation-stopping listener
 *   can no longer leave the process "inside a command" for good.
 *
 * The Messenger events are subscribed by name, so symfony/messenger stays optional. The bundle wires the memo through
 * `$resettables` as an iterator of IGNORE_ON_UNINITIALIZED_REFERENCE references: a boundary only clears an instance
 * the unit of work already built and never builds one itself.
 *
 * @internal registered by the bundle when overrides are enabled; the constructor is not a public API
 */
final class TranslationOverridesClearSubscriber implements EventSubscriberInterface
{
    /** Messenger event names (class names), subscribed without requiring symfony/messenger. */
    public const MESSENGER_WORKER_MESSAGE_RECEIVED = 'Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent';
    public const MESSENGER_WORKER_MESSAGE_HANDLED  = 'Symfony\Component\Messenger\Event\WorkerMessageHandledEvent';
    public const MESSENGER_WORKER_MESSAGE_FAILED   = 'Symfony\Component\Messenger\Event\WorkerMessageFailedEvent';
    public const MESSENGER_WORKER_STOPPED          = 'Symfony\Component\Messenger\Event\WorkerStoppedEvent';
    /** Dispatched by `messenger:failed:retry` only, when a message is skipped. */
    public const MESSENGER_WORKER_MESSAGE_SKIPPED = 'Symfony\Component\Messenger\Event\WorkerMessageSkipEvent';

    /** Console event names (ConsoleEvents::COMMAND / ERROR / TERMINATE). */
    public const CONSOLE_COMMAND   = 'console.command';
    public const CONSOLE_ERROR     = 'console.error';
    public const CONSOLE_TERMINATE = 'console.terminate';

    /** The units of work running in this process (process lifetime, bounded by the nesting depth). */
    private readonly UnitOfWorkFrames $units;

    /**
     * @param iterable<ResetInterface> $resettables the override map memo ({@see TranslationOverrides})
     */
    public function __construct(
        private readonly iterable $resettables,
    ) {
        $this->units = new UnitOfWorkFrames();
    }

    /**
     * @return array<string, array{0: string, 1: int}|list<array{0: string, 1: int}>|string>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST                   => ['onRequest', PHP_INT_MAX],
            KernelEvents::FINISH_REQUEST            => ['onFinishRequest', PHP_INT_MAX],
            KernelEvents::TERMINATE                 => [['onRequestBoundaryEnded', PHP_INT_MAX], ['clearState', 0]],
            self::MESSENGER_WORKER_MESSAGE_RECEIVED => ['onMessageReceived', PHP_INT_MAX],
            self::MESSENGER_WORKER_MESSAGE_HANDLED  => [['onMessageEnded', PHP_INT_MAX], ['clearState', -4096]],
            self::MESSENGER_WORKER_MESSAGE_FAILED   => [['onMessageEnded', PHP_INT_MAX], ['clearState', -4096]],
            self::MESSENGER_WORKER_MESSAGE_SKIPPED  => ['onMessageEnded', PHP_INT_MAX],
            self::MESSENGER_WORKER_STOPPED          => ['onWorkerStopped', PHP_INT_MAX],
            self::CONSOLE_COMMAND                   => ['onConsoleCommand', PHP_INT_MAX],
            self::CONSOLE_ERROR                     => ['onConsoleError', PHP_INT_MAX],
            self::CONSOLE_TERMINATE                 => [['onConsoleCommandEnded', PHP_INT_MAX], ['onConsoleTerminate', -4096]],
        ];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $this->units->requestStarted();
        $this->clearState();
    }

    public function onFinishRequest(FinishRequestEvent $event): void
    {
        if ($event->isMainRequest()) {
            $this->units->requestFinished();
        }
    }

    /**
     * `kernel.terminate` (PHP_INT_MAX): a top-level request boundary resets the main-request counter, even when the
     * request's `kernel.finish_request` never came (audit NR-b M1).
     */
    public function onRequestBoundaryEnded(): void
    {
        $this->units->requestBoundaryEnded();
    }

    /**
     * Start of a Messenger message: nothing of the previous message or the worker's idle time is kept.
     */
    public function onMessageReceived(): void
    {
        $this->units->messageReceived();
        $this->clearState();
    }

    /**
     * A message was handled, failed or skipped (PHP_INT_MAX): no message is in flight any more.
     */
    public function onMessageEnded(): void
    {
        $this->units->messageFinished();
    }

    /**
     * The worker stopped: no message is in flight any more.
     */
    public function onWorkerStopped(): void
    {
        $this->units->workerStopped();
    }

    /**
     * Start of a command run: clears only for the outermost one (outside a message and a main request).
     */
    public function onConsoleCommand(ConsoleCommandEvent $event): void
    {
        if ($this->units->commandStarted($event)) {
            $this->clearState();
        }
    }

    /**
     * A command run failed: its frame is marked (dropped at the next unit when no terminate follows).
     */
    public function onConsoleError(ConsoleErrorEvent $event): void
    {
        $this->units->commandFailed($event);
    }

    /**
     * End of a command run (PHP_INT_MAX, before any listener that could throw or stop the propagation).
     */
    public function onConsoleCommandEnded(ConsoleTerminateEvent $event): void
    {
        $this->units->commandEnded($event);
    }

    /**
     * End of a command run (late): clears only when that run started as the outermost unit.
     */
    public function onConsoleTerminate(ConsoleTerminateEvent $event): void
    {
        if ($this->units->endsOutermostUnit($event)) {
            $this->clearState();
        }
    }

    /**
     * Drops the override map memo. Called at every unit-of-work boundary (main request start, kernel.terminate,
     * Messenger message received / handled / failed, outermost console command start / terminate).
     */
    public function clearState(): void
    {
        foreach ($this->resettables as $service) {
            $service->reset();
        }
    }
}
