<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Tests\Unit\MachineTranslation;

use Nowo\TranslationYamlToolsBundle\MachineTranslation\MachineTranslationLocaleMapper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MachineTranslationLocaleMapper::class)]
final class MachineTranslationLocaleMapperTest extends TestCase
{
    public function testCanonicalLocaleKeyNormalizesSeparatorsAndCase(): void
    {
        self::assertSame('pt_br', MachineTranslationLocaleMapper::canonicalLocaleKey('pt_BR'));
        self::assertSame('pt_br', MachineTranslationLocaleMapper::canonicalLocaleKey('pt-br'));
        self::assertSame('zh_cn', MachineTranslationLocaleMapper::canonicalLocaleKey('ZH_cn'));
    }

    public function testMapReturnsConfiguredApiCode(): void
    {
        $mapper = new MachineTranslationLocaleMapper(['pt_br' => 'pt-br']);
        self::assertSame('pt-br', $mapper->map('pt_BR'));
        self::assertSame('pt-br', $mapper->map('PT-br'));
    }

    public function testMapReturnsNullWhenUnknown(): void
    {
        $mapper = new MachineTranslationLocaleMapper(['en' => 'EN-GB']);
        self::assertNull($mapper->map('de'));
    }

    public function testCanonicalLocaleKeyEmptyOrWhitespace(): void
    {
        self::assertSame('', MachineTranslationLocaleMapper::canonicalLocaleKey(''));
        self::assertSame('', MachineTranslationLocaleMapper::canonicalLocaleKey('   '));
    }

    public function testMapReturnsNullForEmptyLocale(): void
    {
        $mapper = new MachineTranslationLocaleMapper(['en' => 'EN']);
        self::assertNull($mapper->map(''));
        self::assertNull($mapper->map('  '));
    }
}
