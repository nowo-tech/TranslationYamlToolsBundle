<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\TextOverride\EventSubscriber;

use Nowo\TranslationYamlToolsBundle\TextOverride\TranslationOverrides;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\Service\ResetInterface;

use function max;

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
 *   outer unit's memo: the nesting depth is counted on `console.command` / `console.terminate`, and the in-flight flag
 *   is set when a message is received and unset when it is handled, failed or the worker stops (same pattern as
 *   BeaconBundle 1.24.0).
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

    /** Console event names (ConsoleEvents::COMMAND / TERMINATE). */
    public const CONSOLE_COMMAND   = 'console.command';
    public const CONSOLE_TERMINATE = 'console.terminate';

    /**
     * Console commands currently running in this process (nested in-process runs count). Process-lifetime counter,
     * bounded by the nesting depth; intentionally not reset by `kernel.reset`.
     */
    private int $consoleDepth = 0;

    /** A Messenger message is being handled (between received and handled / failed / worker stopped). */
    private bool $messageInFlight = false;

    /**
     * @param iterable<ResetInterface> $resettables the override map memo ({@see TranslationOverrides})
     */
    public function __construct(
        private readonly iterable $resettables,
    ) {
    }

    /**
     * @return array<string, array{0: string, 1: int}|string>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST                   => ['onRequest', 4096],
            KernelEvents::TERMINATE                 => 'clearState',
            self::MESSENGER_WORKER_MESSAGE_RECEIVED => ['onMessageReceived', 4096],
            self::MESSENGER_WORKER_MESSAGE_HANDLED  => ['onMessageFinished', -4096],
            self::MESSENGER_WORKER_MESSAGE_FAILED   => ['onMessageFinished', -4096],
            self::MESSENGER_WORKER_STOPPED          => ['onWorkerStopped', -4096],
            self::CONSOLE_COMMAND                   => ['onConsoleCommand', 4096],
            self::CONSOLE_TERMINATE                 => ['onConsoleTerminate', -4096],
        ];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
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
        $this->clearState();
    }

    /**
     * End of a Messenger message (handled or failed).
     */
    public function onMessageFinished(): void
    {
        // @igor-ignore - Unit-of-work flag, set and unset at the Messenger boundaries.
        $this->messageInFlight = false;
        $this->clearState();
    }

    /**
     * The worker stopped (possibly after a skipped message): no message is in flight any more.
     */
    public function onWorkerStopped(): void
    {
        // @igor-ignore - Unit-of-work flag, set and unset at the Messenger boundaries.
        $this->messageInFlight = false;
    }

    /**
     * Start of a command: clears only when the outermost command starts outside a Messenger message.
     */
    public function onConsoleCommand(): void
    {
        // @igor-ignore - Nesting counter of the running commands (no request data).
        if (++$this->consoleDepth === 1 && !$this->messageInFlight) {
            $this->clearState();
        }
    }

    /**
     * End of a command: clears only when the outermost command ends outside a Messenger message.
     */
    public function onConsoleTerminate(): void
    {
        // @igor-ignore - Nesting counter of the running commands (no request data).
        $this->consoleDepth = max(0, $this->consoleDepth - 1);
        if ($this->consoleDepth === 0 && !$this->messageInFlight) {
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
