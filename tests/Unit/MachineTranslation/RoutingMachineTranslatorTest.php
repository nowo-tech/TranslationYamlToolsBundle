<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Tests\Unit\MachineTranslation;

use InvalidArgumentException;
use Nowo\TranslationYamlToolsBundle\MachineTranslation\MachineTranslatorInterface;
use Nowo\TranslationYamlToolsBundle\MachineTranslation\RoutingMachineTranslator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RoutingMachineTranslator::class)]
final class RoutingMachineTranslatorTest extends TestCase
{
    /**
     * @return array<string, MachineTranslatorInterface>
     */
    private function stubs(): array
    {
        return [
            'google'         => $this->createMock(MachineTranslatorInterface::class),
            'deepl'          => $this->createMock(MachineTranslatorInterface::class),
            'libretranslate' => $this->createMock(MachineTranslatorInterface::class),
        ];
    }

    public function testUsesDefaultWhenNoLocaleRules(): void
    {
        $stubs = $this->stubs();
        $stubs['google']->expects(self::once())->method('translate')->with('t', 'en', 'de')->willReturn('G');
        $stubs['deepl']->expects(self::never())->method('translate');
        $router = new RoutingMachineTranslator($stubs, 'google', []);
        self::assertSame('G', $router->translate('t', 'en', 'de'));
    }

    public function testTargetLocaleOverridesDefault(): void
    {
        $stubs = $this->stubs();
        $stubs['libretranslate']->expects(self::once())->method('translate')->willReturn('L');
        $stubs['google']->expects(self::never())->method('translate');
        $router = new RoutingMachineTranslator($stubs, 'google', ['pt_br' => 'libretranslate']);
        self::assertSame('L', $router->translate('x', 'en', 'pt_BR'));
    }

    public function testSourceLocaleUsedWhenTargetNotMapped(): void
    {
        $stubs = $this->stubs();
        $stubs['deepl']->expects(self::once())->method('translate')->willReturn('D');
        $stubs['google']->expects(self::never())->method('translate');
        $router = new RoutingMachineTranslator($stubs, 'google', ['fr' => 'deepl']);
        self::assertSame('D', $router->translate('x', 'fr', 'de'));
    }

    public function testTargetTakesPrecedenceOverSource(): void
    {
        $stubs = $this->stubs();
        $stubs['deepl']->expects(self::once())->method('translate')->willReturn('D');
        $stubs['libretranslate']->expects(self::never())->method('translate');
        $router = new RoutingMachineTranslator($stubs, 'google', [
            'de'    => 'libretranslate',
            'pt_br' => 'deepl',
        ]);
        self::assertSame('D', $router->translate('x', 'de', 'pt_BR'));
    }

    public function testThrowsWhenDefaultBackendNotInTranslatorsMap(): void
    {
        $google = $this->createMock(MachineTranslatorInterface::class);
        $router = new RoutingMachineTranslator(['google' => $google], 'deepl', []);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown machine translator backend');
        $router->translate('x', 'en', 'de');
    }
}
