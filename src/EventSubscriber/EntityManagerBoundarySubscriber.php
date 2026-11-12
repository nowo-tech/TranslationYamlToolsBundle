<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\EventSubscriber;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\TranslationYamlToolsBundle\Doctrine\PendingWork;
use Nowo\TranslationYamlToolsBundle\Doctrine\TranslationYamlToolsEntityManager;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
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
 * Clears the bundle's own entity manager at every OUTERMOST unit-of-work boundary (1.18.0, REQ-RUNTIME-002 rule 4), so
 * text overrides and missing-log rows loaded by the Web UI, a command or a message handler never stay managed across
 * requests, and an unflushed change of an abandoned unit is never written by a later, unrelated bundle flush.
 *
 * Which manager:
 * - the private manager ({@see TranslationYamlToolsEntityManager}, the
 *   zero-config default) is dropped through `$resettables` (its `reset()`); the next call creates a fresh one;
 * - the dedicated manager named by `doctrine.entity_manager` is cleared through `$entityManagers`, or recreated by name
 *   through the registry when a failed write closed it. Nothing is wired when that name is the host's default manager
 *   (REQ-RUNTIME-002 rule 7: the host clears its own managers);
 * - `doctrine.private_entity_manager: false` (deprecated, pre-1.18 behaviour): nothing, the bundle entities live on the
 *   application's manager.
 *
 * Boundaries:
 * - HTTP: the main `kernel.request` (priority 4096) and `kernel.terminate` (priority -4096), only outside a console
 *   command and a Messenger message (an in-process `HttpKernel::handle()` from a command or a handler must not clear
 *   the unit that runs it). Sub-requests (fragments, ESI) share their main request's unit of work. The terminate
 *   listener runs after the missing-log flush (priority -1024);
 * - Messenger: when a message is received (priority 4096) and after it is handled or failed (priority -4096);
 *   a skipped message (`WorkerMessageSkipEvent`) or a stopped worker ends the in-flight message without clearing;
 * - console: when the OUTERMOST command starts and terminates, only outside a Messenger message and outside a main
 *   request. A command run in-process from another command, a message handler or a controller
 *   (`Application::doRun()`) does not clear the outer unit's manager.
 *
 * State: the running console commands ({@see ConsoleUnitStack}, 1.18.1 audit NM: recorded on `console.command` and
 * dropped on their own `console.terminate`, both at PHP_INT_MAX, so a terminate listener that throws or stops the
 * propagation, or a `console.error` listener that throws, can no longer leave a command "running" forever and stop
 * every later HTTP clear); a message flag set on received and unset on handled / failed / skipped / worker stopped; a
 * request flag set on the main `kernel.request` and unset on its `kernel.finish_request`. A main request and a
 * Messenger message snapshot the running commands when they start and restore them when they end, which drops a
 * command run inside them whose `console.terminate` never ran.
 *
 * The managers, resettables and the registry arrive as iterators of IGNORE_ON_UNINITIALIZED_REFERENCE references, so
 * a boundary only touches a manager the unit of work already built and never builds one itself. The Messenger events
 * are subscribed by name, so symfony/messenger stays optional.
 *
 * @internal registered by the bundle; the constructor is not a public API
 */
final class EntityManagerBoundarySubscriber implements EventSubscriberInterface
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
     * Running commands when the current main request started, restored when it ends.
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

    /** A main request is being handled (between its `kernel.request` and its `kernel.finish_request`). */
    private bool $requestInFlight = false;

    /**
     * @param iterable<ResetInterface> $resettables the private manager (empty without it)
     * @param iterable<EntityManagerInterface> $entityManagers the dedicated manager (empty without one)
     * @param iterable<ManagerRegistry> $registries the Doctrine registry, to recreate a closed dedicated manager
     * @param string|null $managerName `doctrine.entity_manager`
     * @param LoggerInterface|null $logger Warns when a boundary discards unflushed bundle work (audit ND-L2)
     */
    public function __construct(
        private readonly iterable $resettables = [],
        private readonly iterable $entityManagers = [],
        private readonly iterable $registries = [],
        private readonly ?string $managerName = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
        $this->commands = new ConsoleUnitStack();
    }

    /**
     * @return array<string, array{0: string, 1: int}|list<array{0: string, 1: int}>>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST                   => ['onRequest', 4096],
            KernelEvents::FINISH_REQUEST            => ['onFinishRequest', -4096],
            KernelEvents::TERMINATE                 => ['onTerminate', -4096],
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

    /**
     * Start of a main request: clears only outside a command and a message (an in-process HttpKernel::handle()).
     */
    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $outermost = $this->commands->depth() === 0 && !$this->messageInFlight;
        // @igor-ignore - Unit-of-work flag, set and unset at the main request boundaries.
        $this->requestInFlight = true;
        // @igor-ignore - Console bookkeeping snapshot of the request boundary (command objects only).
        $this->requestCommands = $this->commands->snapshot();
        if ($outermost) {
            $this->clearState();
        }
    }

    public function onFinishRequest(FinishRequestEvent $event): void
    {
        if ($event->isMainRequest()) {
            // @igor-ignore - Unit-of-work flag, set and unset at the main request boundaries.
            $this->requestInFlight = false;
            if ($this->requestCommands !== null) {
                $this->commands->restore($this->requestCommands);
            }
        }
    }

    /**
     * End of a request: clears only outside a command and a message (an in-process HttpKernel::handle()).
     */
    public function onTerminate(): void
    {
        if ($this->requestCommands !== null) {
            $this->commands->restore($this->requestCommands);
            // @igor-ignore - Console bookkeeping snapshot of the request boundary (command objects only).
            $this->requestCommands = null;
        }

        if ($this->commands->depth() === 0 && !$this->messageInFlight) {
            $this->clearState();
        }
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
        $this->endMessage();
        $this->clearState();
    }

    /**
     * A received message was skipped, or the worker stopped: no message is in flight any more.
     */
    public function onWorkerStopped(): void
    {
        $this->endMessage();
    }

    /**
     * Start of a command (PHP_INT_MAX): records it; clears only for the outermost command outside a message and a main
     * request.
     */
    public function onConsoleCommand(ConsoleCommandEvent $event): void
    {
        $outermost = $this->isOutermostUnit();
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
     * Drops the private manager; clears the dedicated manager when it is open, recreates it by name when a failed
     * write closed it; nothing for a manager that was never built.
     */
    public function clearState(): void
    {
        foreach ($this->resettables as $resettable) {
            $resettable->reset();
        }

        foreach ($this->entityManagers as $entityManager) {
            if ($entityManager->isOpen()) {
                // Inspected only to log; it is cleared whatever is pending.
                $pending = $this->logger instanceof LoggerInterface ? PendingWork::count($entityManager) : 0;
                if ($pending > 0) {
                    $this->logger?->warning('Discarding {count} unflushed change(s) of the {manager} entity manager at a unit-of-work boundary.', ['count' => $pending, 'manager' => $this->managerName]);
                }
                $entityManager->clear();

                continue;
            }

            if ($this->managerName !== null) {
                foreach ($this->registries as $registry) {
                    $registry->resetManager($this->managerName);
                }
            }
        }
    }

    private function endMessage(): void
    {
        // @igor-ignore - Unit-of-work flag, set and unset at the Messenger boundaries.
        $this->messageInFlight = false;
        if ($this->messageCommands !== null) {
            $this->commands->restore($this->messageCommands);
            // @igor-ignore - Console bookkeeping snapshot of the message boundary (command objects only).
            $this->messageCommands = null;
        }
    }

    private function isOutermostUnit(): bool
    {
        return $this->commands->depth() === 0 && !$this->messageInFlight && !$this->requestInFlight;
    }
}
