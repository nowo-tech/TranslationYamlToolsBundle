<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Tests\Unit\MachineTranslation;

use Nowo\TranslationYamlToolsBundle\MachineTranslation\MachineTranslatorInterface;
use Nowo\TranslationYamlToolsBundle\MachineTranslation\ThrottledMachineTranslator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ThrottledMachineTranslator::class)]
final class ThrottledMachineTranslatorTest extends TestCase
{
    public function testDelegatesWithoutDelayWhenIntervalZero(): void
    {
        $inner = $this->createMock(MachineTranslatorInterface::class);
        $inner->expects(self::once())->method('translate')->with('hi', 'en', 'es')->willReturn('hola');

        $throttled = new ThrottledMachineTranslator($inner, 0);
        self::assertSame('hola', $throttled->translate('hi', 'en', 'es'));
    }

    public function testPacesCallsWhenIntervalPositive(): void
    {
        $inner = $this->createMock(MachineTranslatorInterface::class);
        $inner->expects(self::exactly(2))->method('translate')->willReturn('x');

        $throttled = new ThrottledMachineTranslator($inner, 20);
        $start     = microtime(true);
        $throttled->translate('a', 'en', 'es');
        $throttled->translate('b', 'en', 'es');
        $elapsed = microtime(true) - $start;

        self::assertGreaterThanOrEqual(0.015, $elapsed);
    }
}
