<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\TextOverride\EventSubscriber;

use Nowo\TranslationYamlToolsBundle\EventSubscriber\ConsoleUnitStack;
use Nowo\TranslationYamlToolsBundle\TextOverride\TranslationOverrides;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleErrorEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
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
 * Boundaries:
 * - HTTP: the start of every main request (priority 4096, before controllers and listeners translate) and
 *   `kernel.terminate`. Sub-requests (fragments, ESI) share their main request's memo;
 * - Messenger: when a message is received (priority 4096) and after it is handled or failed (priority -4096);
 * - console: when the OUTERMOST command starts and terminates, and only while no Messenger message is in flight. A
 *   command run in-process from another command or from a message handler (`Application::doRun()`) does not clear the
 *   outer unit's memo: the running commands are recorded on `console.command` and dropped on their own
 *   `console.terminate`, both at PHP_INT_MAX ({@see ConsoleUnitStack}, 1.18.1 audit NM: a terminate listener that
 *   throws or stops the propagation, or a `console.error` listener that throws, no longer leaves a command "running"
 *   forever), and the in-flight flag is set when a message is received and unset when it is handled, failed, skipped
 *   or the worker stops. A main request and a message snapshot the running commands when they start and restore them
 *   when they end.
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
    public const MESSENGER_WORKER_MESSAGE_SKIPPED  = 'Symfony\Component\Messenger\Event\WorkerMessageSkipEvent';

    /** Console event names (ConsoleEvents::COMMAND / TERMINATE). */
    public const CONSOLE_COMMAND   = 'console.command';
    public const CONSOLE_TERMINATE = 'console.terminate';
    public const CONSOLE_ERROR     = 'console.error';

    /**
     * Console commands currently running in this process (nested in-process runs count). Process-lifetime state,
     * bounded by the nesting depth; intentionally not reset by `kernel.reset`.
     */
    private readonly ConsoleUnitStack $commands;

    /**
     * Running commands when the current main request started, restored at `kernel.terminate`.
     *
     * @var list<array{Command, bool}>|null
     */
    private ?array $requestCommands = null;

    /**
     * Running commands when the current Messenger message was received, restored when it ends.
     *
     * @var list<array{Command, bool}>|null
     */
    private ?array $messageCommands = null;

    /** A Messenger message is being handled (between received and handled / failed / worker stopped). */
    private bool $messageInFlight = false;

    /**
     * @param iterable<ResetInterface> $resettables the override map memo ({@see TranslationOverrides})
     */
    public function __construct(
        private readonly iterable $resettables,
    ) {
        $this->commands = new ConsoleUnitStack();
    }

    /**
     * @return array<string, array{0: string, 1: int}|list<array{0: string, 1: int}>|string>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST                   => ['onRequest', 4096],
            KernelEvents::TERMINATE                 => 'onTerminate',
            self::MESSENGER_WORKER_MESSAGE_RECEIVED => ['onMessageReceived', 4096],
            self::MESSENGER_WORKER_MESSAGE_HANDLED  => ['onMessageFinished', -4096],
            self::MESSENGER_WORKER_MESSAGE_FAILED   => ['onMessageFinished', -4096],
            self::MESSENGER_WORKER_STOPPED          => ['onWorkerStopped', -4096],
            self::MESSENGER_WORKER_MESSAGE_SKIPPED  => ['onWorkerStopped', -4096],
            self::CONSOLE_COMMAND                   => ['onConsoleCommand', PHP_INT_MAX],
            self::CONSOLE_TERMINATE                 => [['onConsoleCommandEnded', PHP_INT_MAX], ['onConsoleTerminate', -4096]],
            self::CONSOLE_ERROR                     => ['onConsoleError', PHP_INT_MAX],
        ];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        // @igor-ignore - Console bookkeeping snapshot of the request boundary (command objects only).
        $this->requestCommands = $this->commands->snapshot();
        $this->clearState();
    }

    /**
     * End of a request: restores the running commands of its start, then clears.
     */
    public function onTerminate(): void
    {
        if ($this->requestCommands !== null) {
            $this->commands->restore($this->requestCommands);
            // @igor-ignore - Console bookkeeping snapshot of the request boundary (command objects only).
            $this->requestCommands = null;
        }

        $this->clearState();
    }

    /**
     * Start of a Messenger message: nothing of the previous message or the worker's idle time is kept.
     */
    public function onMessageReceived(): void
    {
        // @igor-ignore - Unit-of-work flag, set and unset at the Messenger boundaries.
        $this->messageInFlight = true;
        // @igor-ignore - Console bookkeeping snapshot of the message boundary (command objects only).
        $this->messageCommands = $this->commands->snapshot();
        $this->clearState();
    }

    /**
     * End of a Messenger message (handled or failed).
     */
    public function onMessageFinished(): void
    {
        $this->onWorkerStopped();
        $this->clearState();
    }

    /**
     * A received message was skipped, or the worker stopped: no message is in flight any more.
     */
    public function onWorkerStopped(): void
    {
        // @igor-ignore - Unit-of-work flag, set and unset at the Messenger boundaries.
        $this->messageInFlight = false;
        if ($this->messageCommands !== null) {
            $this->commands->restore($this->messageCommands);
            // @igor-ignore - Console bookkeeping snapshot of the message boundary (command objects only).
            $this->messageCommands = null;
        }
    }

    /**
     * Start of a command (PHP_INT_MAX): records it; clears only when the outermost command starts outside a Messenger
     * message.
     */
    public function onConsoleCommand(ConsoleCommandEvent $event): void
    {
        $outermost = $this->commands->depth() === 0 && !$this->messageInFlight;
        $this->commands->start($event->getCommand(), $outermost);
        if ($outermost) {
            $this->clearState();
        }
    }

    /**
     * End of a command (PHP_INT_MAX, before any listener that could throw or stop the propagation): drops it from the
     * running commands; a command that was never recorded changes nothing.
     */
    public function onConsoleCommandEnded(ConsoleTerminateEvent $event): void
    {
        $this->commands->end($event->getCommand());
    }

    /**
     * A command failed (PHP_INT_MAX): its run is over, so it is dropped from the running commands before any error
     * listener can throw (Symfony then skips `console.terminate`). Only a recorded command counts; an error without a
     * command (e.g. a command that was not found) changes nothing.
     */
    public function onConsoleError(ConsoleErrorEvent $event): void
    {
        $this->commands->end($event->getCommand());
    }

    /**
     * End of a command (late): clears only when the command that ended started as the outermost unit.
     */
    public function onConsoleTerminate(ConsoleTerminateEvent $event): void
    {
        if ($this->commands->endsOutermostUnit($event->getCommand())) {
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
