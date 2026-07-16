<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Tests\Unit\MachineTranslation;

use InvalidArgumentException;
use Nowo\TranslationYamlToolsBundle\MachineTranslation\LibreTranslateBaseUrlGuard;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(LibreTranslateBaseUrlGuard::class)]
final class LibreTranslateBaseUrlGuardTest extends TestCase
{
    public function testAllowsDefaultHost(): void
    {
        $guard = new LibreTranslateBaseUrlGuard(['libretranslate.com']);
        $guard->assertAllowed('https://libretranslate.com');
        $guard->assertAllowed('https://api.libretranslate.com');
        $this->addToAssertionCount(1);
    }

    public function testRejectsUnknownHost(): void
    {
        $guard = new LibreTranslateBaseUrlGuard(['libretranslate.com']);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not in libretranslate_allowed_hosts');
        $guard->assertAllowed('https://evil.example');
    }

    public function testRejectsHttpByDefault(): void
    {
        $guard = new LibreTranslateBaseUrlGuard(['libretranslate.com']);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must use https');
        $guard->assertAllowed('http://libretranslate.com');
    }

    public function testAllowsHttpWhenEnabled(): void
    {
        $guard = new LibreTranslateBaseUrlGuard(['localhost'], true);
        $guard->assertAllowed('http://localhost:5000');
        $this->addToAssertionCount(1);
    }

    public function testRejectsUserinfo(): void
    {
        $guard = new LibreTranslateBaseUrlGuard(['libretranslate.com']);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('userinfo');
        $guard->assertAllowed('https://user:pass@libretranslate.com');
    }
}
