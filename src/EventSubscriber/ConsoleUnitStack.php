<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\EventSubscriber;

use Symfony\Component\Console\Command\Command;

use function array_slice;
use function count;

/**
 * Console bookkeeping of a unit-of-work boundary subscriber (1.18.1, audit NM): the commands running in this process
 * (nested in-process runs included), each with whether it started as the OUTERMOST unit.
 *
 * A plain `++` on `console.command` / `--` on a late `console.terminate` leaks in a long-lived process: a terminate
 * listener that throws or stops the propagation before the decrement, or a `console.error` listener that throws
 * (Symfony then skips `console.terminate`), leaves the depth above zero forever, so every later boundary gated on "no
 * command running" stops clearing. Here:
 * - {@see self::start()} (on `console.command` at PHP_INT_MAX) records the command;
 * - {@see self::end()} (on `console.error` and `console.terminate` at PHP_INT_MAX) drops that command and every
 *   command recorded after it; a command that was never recorded (or none) changes nothing (never a blind decrement);
 * - {@see self::endsOutermostUnit()} (at the subscriber's late `console.terminate` priority) tells, once, whether the
 *   command that just ended was the outermost unit;
 * - the enclosing unit (main request, Messenger message) takes a {@see self::snapshot()} when it starts and
 *   {@see self::restore()}s it when it ends, which drops a command whose `console.terminate` never ran.
 *
 * Process-lifetime state bounded by the nesting depth; it holds no request data.
 *
 * @internal
 */
final class ConsoleUnitStack
{
    /** @var list<array{Command, bool}> running commands, outermost first, with their "outermost unit" flag */
    private array $running = [];

    /** The command whose end {@see self::end()} recorded as the end of the outermost unit, until it is consumed. */
    private ?Command $endingOutermost = null;

    public function depth(): int
    {
        return count($this->running);
    }

    /**
     * Records a starting command; a missing command (none resolved) is not recorded.
     */
    public function start(?Command $command, bool $outermost): void
    {
        if ($command instanceof Command) {
            // @igor-ignore - Console nesting bookkeeping (command objects only, no request data).
            $this->running[] = [$command, $outermost];
        }
    }

    /**
     * Records the end of a command: drops it and every command recorded after it (their terminate never ran). A
     * command that was never recorded changes nothing.
     */
    public function end(?Command $command): void
    {
        for ($index = count($this->running) - 1; $index >= 0; --$index) {
            if ($this->running[$index][0] !== $command) {
                continue;
            }

            // @igor-ignore - Console nesting bookkeeping (command objects only, no request data).
            $this->endingOutermost = $this->running[$index][1] ? $command : null;
            // @igor-ignore - Console nesting bookkeeping (command objects only, no request data).
            $this->running = array_slice($this->running, 0, $index);

            return;
        }
    }

    /**
     * Whether $command is the outermost unit that {@see self::end()} just recorded; true only once.
     */
    public function endsOutermostUnit(?Command $command): bool
    {
        if (!$command instanceof Command || $this->endingOutermost !== $command) {
            return false;
        }

        // @igor-ignore - Console nesting bookkeeping (command objects only, no request data).
        $this->endingOutermost = null;

        return true;
    }

    /**
     * @return list<array{Command, bool}>
     */
    public function snapshot(): array
    {
        return $this->running;
    }

    /**
     * @param list<array{Command, bool}> $snapshot taken when the enclosing unit started
     */
    public function restore(array $snapshot): void
    {
        // @igor-ignore - Console nesting bookkeeping (command objects only, no request data).
        $this->running = $snapshot;
    }
}
