<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Tests\Unit\EventSubscriber;

use Nowo\TranslationYamlToolsBundle\EventSubscriber\ConsoleUnitStack;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;

#[CoversClass(ConsoleUnitStack::class)]
final class ConsoleUnitStackTest extends TestCase
{
    public function testEndDropsTheCommandAndEveryCommandRecordedAfterIt(): void
    {
        $stack  = new ConsoleUnitStack();
        $outer  = new Command('outer');
        $nested = new Command('nested');
        $leaked = new Command('leaked');

        $stack->start($outer, true);
        $stack->start($nested, false);
        $stack->start($leaked, false);
        self::assertSame(3, $stack->depth());

        $stack->end($nested);
        self::assertSame(1, $stack->depth(), 'The leaked command recorded after the nested one is dropped too.');
        self::assertFalse($stack->endsOutermostUnit($nested));

        $stack->end($outer);
        self::assertSame(0, $stack->depth());
        self::assertFalse($stack->endsOutermostUnit($nested));
        self::assertTrue($stack->endsOutermostUnit($outer));
        self::assertFalse($stack->endsOutermostUnit($outer), 'The outermost end is reported once.');
    }

    public function testUnknownOrMissingCommandsChangeNothing(): void
    {
        $stack = new ConsoleUnitStack();
        $outer = new Command('outer');

        $stack->start(null, true);
        self::assertSame(0, $stack->depth());
        $stack->start($outer, true);
        $stack->end(new Command('unknown'));
        $stack->end(null);
        self::assertSame(1, $stack->depth());
        self::assertFalse($stack->endsOutermostUnit(null));
    }

    public function testTheSameCommandNestedInItselfEndsTheInnermostRunFirst(): void
    {
        $stack   = new ConsoleUnitStack();
        $command = new Command('recursive');

        $stack->start($command, true);
        $stack->start($command, false);
        $stack->end($command);
        self::assertSame(1, $stack->depth());
        self::assertFalse($stack->endsOutermostUnit($command));
        $stack->end($command);
        self::assertTrue($stack->endsOutermostUnit($command));
    }

    public function testRestoreBringsBackASnapshot(): void
    {
        $stack = new ConsoleUnitStack();
        $outer = new Command('outer');

        $stack->start($outer, true);
        $snapshot = $stack->snapshot();
        $stack->start(new Command('leaked'), false);
        $stack->restore($snapshot);
        self::assertSame([[$outer, true]], $stack->snapshot());
    }
}
