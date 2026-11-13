<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\EventSubscriber;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\TranslationYamlToolsBundle\Doctrine\PendingWork;
use Nowo\TranslationYamlToolsBundle\Doctrine\TranslationYamlToolsEntityManager;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleErrorEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\FinishRequestEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\Service\ResetInterface;
use Throwable;

use const PHP_INT_MAX;
use const PHP_INT_MIN;

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
 * Boundaries (every unit-of-work state change at PHP_INT_MAX, through {@see UnitOfWorkFrames}, 1.18.1 audit NM):
 * - HTTP: the main `kernel.request` (PHP_INT_MAX) and `kernel.terminate` (priority -4096), only for the outermost
 *   unit: not inside a console command, a Messenger message or another main request (an in-process
 *   `HttpKernel::handle()` must not clear the unit that runs it). Sub-requests (fragments, ESI) share their main
 *   request's unit of work. The terminate listener runs after the missing-log flush (priority -1024);
 * - Messenger: when a message is received (PHP_INT_MAX) and after it is handled or failed (priority -4096), only
 *   outside another message (a worker run in-process by a handler keeps the outer message's unit, audit NX CR1). A
 *   skipped message (`WorkerMessageSkipEvent`, dispatched by `messenger:failed:retry` only, or `shouldHandle(false)` in
 *   a received listener, audit NX CR3), the worker's next `WorkerRunningEvent` or its stop ends the in-flight message
 *   without clearing;
 * - console: when the OUTERMOST run starts (PHP_INT_MAX) and terminates (priority -4096), only outside a Messenger
 *   message and a main request. A command run in-process from another command, a message handler or a controller
 *   (`Application::doRun()`) does not clear the outer unit's manager. Each run is a frame keyed by its command and its
 *   input, removed by its own `console.terminate`; a run whose `console.error` listener threw (no terminate follows)
 *   is dropped at the next request, message, command or worker stop, so a throwing or propagation-stopping listener
 *   can no longer leave the process "inside a command" for good (before 1.18.1 the bundle manager was then never
 *   cleared between requests again).
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
    public const MESSENGER_WORKER_STARTED          = 'Symfony\Component\Messenger\Event\WorkerStartedEvent';
    public const MESSENGER_WORKER_RUNNING          = 'Symfony\Component\Messenger\Event\WorkerRunningEvent';
    /** Dispatched by `messenger:failed:retry` only, when a message is skipped. */
    public const MESSENGER_WORKER_MESSAGE_SKIPPED = 'Symfony\Component\Messenger\Event\WorkerMessageSkipEvent';

    /** Console event names (ConsoleEvents::COMMAND / ERROR / TERMINATE). */
    public const CONSOLE_COMMAND   = 'console.command';
    public const CONSOLE_ERROR     = 'console.error';
    public const CONSOLE_TERMINATE = 'console.terminate';

    /** The units of work running in this process (process lifetime, bounded by the nesting depth). */
    private readonly UnitOfWorkFrames $units;

    /**
     * @param iterable<ResetInterface> $resettables the private manager (empty without it)
     * @param iterable<EntityManagerInterface> $entityManagers the dedicated manager (empty without one)
     * @param iterable<ManagerRegistry> $registries the Doctrine registry, to recreate a closed dedicated manager
     * @param string|null $managerName `doctrine.entity_manager`
     * @param LoggerInterface|null $logger Warns when a boundary discards unflushed bundle work (audit ND-L2)
     * @param RequestStack|null $requestStack the HTTP kernel's request stack (read only, see {@see UnitOfWorkFrames})
     */
    public function __construct(
        private readonly iterable $resettables = [],
        private readonly iterable $entityManagers = [],
        private readonly iterable $registries = [],
        private readonly ?string $managerName = null,
        private readonly ?LoggerInterface $logger = null,
        ?RequestStack $requestStack = null,
    ) {
        $this->units = new UnitOfWorkFrames($requestStack);
    }

    /**
     * @return array<string, array{0: string, 1: int}|list<array{0: string, 1: int}>>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST                   => ['onRequest', PHP_INT_MAX],
            KernelEvents::FINISH_REQUEST            => ['onFinishRequest', PHP_INT_MAX],
            KernelEvents::TERMINATE                 => [['onRequestBoundaryEnded', PHP_INT_MAX], ['onTerminate', -4096]],
            self::MESSENGER_WORKER_STARTED          => ['onWorkerStarted', PHP_INT_MAX],
            self::MESSENGER_WORKER_MESSAGE_RECEIVED => [['onMessageReceived', PHP_INT_MAX], ['onMessageReceivedDecided', PHP_INT_MIN]],
            self::MESSENGER_WORKER_MESSAGE_HANDLED  => [['onMessageEnded', PHP_INT_MAX], ['onMessageFinished', -4096]],
            self::MESSENGER_WORKER_MESSAGE_FAILED   => [['onMessageEnded', PHP_INT_MAX], ['onMessageFinished', -4096]],
            self::MESSENGER_WORKER_MESSAGE_SKIPPED  => ['onMessageEnded', PHP_INT_MAX],
            self::MESSENGER_WORKER_RUNNING          => ['onWorkerRunning', PHP_INT_MAX],
            self::MESSENGER_WORKER_STOPPED          => ['onWorkerStopped', PHP_INT_MAX],
            self::CONSOLE_COMMAND                   => ['onConsoleCommand', PHP_INT_MAX],
            self::CONSOLE_ERROR                     => ['onConsoleError', PHP_INT_MAX],
            self::CONSOLE_TERMINATE                 => [['onConsoleCommandEnded', PHP_INT_MAX], ['onConsoleTerminate', -4096]],
        ];
    }

    /**
     * Start of a main request: clears only for the outermost unit (not an in-process HttpKernel::handle()).
     */
    public function onRequest(RequestEvent $event): void
    {
        if ($event->isMainRequest() && $this->units->requestStarted($event->getRequest())) {
            $this->clearState();
        }
    }

    public function onFinishRequest(FinishRequestEvent $event): void
    {
        if ($event->isMainRequest()) {
            $this->units->requestFinished($event->getRequest());
        }
    }

    /**
     * `kernel.terminate` (PHP_INT_MAX): a top-level request boundary resets the main-request counter, even when the
     * request's `kernel.finish_request` never came (audit NR-b M1).
     */
    public function onRequestBoundaryEnded(TerminateEvent $event): void
    {
        $this->units->requestBoundaryEnded($event);
    }

    /**
     * End of a request: clears only when nothing else runs (not an in-process HttpKernel::handle()).
     */
    public function onTerminate(TerminateEvent $event): void
    {
        if ($this->units->endsOutermostUnit($event)) {
            $this->clearState();
        }
    }

    /**
     * Start of a Messenger message: nothing of the previous message or the worker's idle time is kept.
     */
    public function onMessageReceived(object $event): void
    {
        if ($this->units->messageReceived($event)) {
            $this->clearState();
        }
    }

    /**
     * A message was handled, failed or skipped (PHP_INT_MAX): no message is in flight any more.
     */
    public function onMessageEnded(object $event): void
    {
        $this->units->messageFinished($event);
    }

    /**
     * End of a Messenger message (handled or failed, late): clears only for the outermost message (audit NX CR1).
     */
    public function onMessageFinished(object $event): void
    {
        if ($this->units->endsOutermostUnit($event)) {
            $this->clearState();
        }
    }

    /**
     * A Messenger worker starts (its own message state, audit NX CR1).
     */
    public function onWorkerStarted(object $event): void
    {
        $this->units->workerStarted($event);
    }

    /**
     * End of the received event (PHP_INT_MIN): a message skipped with `shouldHandle(false)` ends (audit NX CR3).
     */
    public function onMessageReceivedDecided(object $event): void
    {
        $this->units->messageReceivedDecided($event);
    }

    /**
     * The worker finished a message or is idle: its message is not in flight any more.
     */
    public function onWorkerRunning(object $event): void
    {
        $this->units->workerRunning($event);
    }

    /**
     * The worker stopped: no message is in flight any more.
     */
    public function onWorkerStopped(object $event): void
    {
        $this->units->workerStopped($event);
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
                // Inspected only to log; it is cleared whatever is pending. 1.18.2 (audit NX): a unit of work that
                // throws while computing its change sets never stops the clear, and a failed clear recreates the
                // manager by name, so the boundary never throws.
                try {
                    $pending = $this->logger instanceof LoggerInterface ? PendingWork::count($entityManager) : 0;
                    if ($pending > 0) {
                        $this->logger?->warning('Discarding {count} unflushed change(s) of the {manager} entity manager at a unit-of-work boundary.', ['count' => $pending, 'manager' => $this->managerName]);
                    }
                } catch (Throwable $exception) {
                    $this->logger?->warning('Could not inspect the unflushed changes of the {manager} entity manager at a unit-of-work boundary ({exception_class}); they are discarded.', ['manager' => $this->managerName, 'exception_class' => $exception::class]);
                }

                try {
                    $entityManager->clear();

                    continue;
                } catch (Throwable $exception) {
                    $this->logger?->warning('Could not clear the {manager} entity manager at a unit-of-work boundary ({exception_class}); it is recreated.', ['manager' => $this->managerName, 'exception_class' => $exception::class]);
                }
            }

            if ($this->managerName !== null) {
                foreach ($this->registries as $registry) {
                    $registry->resetManager($this->managerName);
                }
            }
        }
    }
}
