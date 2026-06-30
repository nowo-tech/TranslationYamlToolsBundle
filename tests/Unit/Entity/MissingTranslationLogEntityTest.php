<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Tests\Unit\Entity;

use DateTimeImmutable;
use Nowo\TranslationYamlToolsBundle\Entity\MissingTranslationLog;
use Nowo\TranslationYamlToolsBundle\Entity\MissingTranslationLogStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function strlen;

#[CoversClass(MissingTranslationLog::class)]
final class MissingTranslationLogEntityTest extends TestCase
{
    public function testConstructionAndAccessors(): void
    {
        $at = new DateTimeImmutable('2026-01-01 12:00:00');
        $e  = new MissingTranslationLog('mid', 'messages', 'es', $at, '/app/Foo.php:10');

        self::assertNull($e->getId());
        self::assertSame('mid', $e->getMessageId());
        self::assertSame('messages', $e->getDomain());
        self::assertSame('es', $e->getLocale());
        self::assertSame(MissingTranslationLogStatus::Pending, $e->getStatus());
        self::assertSame(1, $e->getHitCount());
        self::assertSame($at, $e->getFirstSeenAt());
        self::assertSame($at, $e->getLastSeenAt());
        self::assertNull($e->getStatusChangedAt());
        self::assertNull($e->getNotes());
        self::assertSame('/app/Foo.php:10', $e->getCallSite());
        self::assertNull($e->getRequestRoute());
        self::assertNull($e->getRequestMethod());
        self::assertNull($e->getRequestPath());
    }

    public function testConstructorWithRequestContext(): void
    {
        $at = new DateTimeImmutable('2026-01-01 12:00:00');
        $e  = new MissingTranslationLog('mid', 'messages', 'es', $at, '/x.php:1', 'route_x', 'GET', '/home');

        self::assertSame('/x.php:1', $e->getCallSite());
        self::assertSame('route_x', $e->getRequestRoute());
        self::assertSame('GET', $e->getRequestMethod());
        self::assertSame('/home', $e->getRequestPath());
    }

    public function testRegisterAdditionalHitsSkipsWhenHitsBelowOne(): void
    {
        $at     = new DateTimeImmutable('2026-01-02 12:00:00');
        $e      = new MissingTranslationLog('a', 'm', 'en', $at);
        $before = $e->getHitCount();
        $e->registerAdditionalHits(0, $at);
        self::assertSame($before, $e->getHitCount());
    }

    public function testRegisterAdditionalHitsAndSetters(): void
    {
        $t0 = new DateTimeImmutable('2026-01-01 12:00:00');
        $t1 = new DateTimeImmutable('2026-01-03 15:00:00');
        $e  = new MissingTranslationLog('x', 'd', 'fr', $t0);

        $e->registerAdditionalHits(2, $t1, '/other.php:2', 'api', 'PUT', '/u');
        self::assertSame(3, $e->getHitCount());
        self::assertSame($t1, $e->getLastSeenAt());
        self::assertSame('/other.php:2', $e->getCallSite());
        self::assertSame('api', $e->getRequestRoute());
        self::assertSame('PUT', $e->getRequestMethod());
        self::assertSame('/u', $e->getRequestPath());

        $e->setStatus(MissingTranslationLogStatus::Added, $t1);
        self::assertSame(MissingTranslationLogStatus::Added, $e->getStatus());
        self::assertSame($t1, $e->getStatusChangedAt());

        $e->setNotes('n');
        self::assertSame('n', $e->getNotes());
        $e->setNotes(null);
        self::assertNull($e->getNotes());
    }

    public function testConstructorTruncatesLongCallSite(): void
    {
        $long = str_repeat('z', 1100);
        $at   = new DateTimeImmutable('2026-01-01 00:00:00');
        $e    = new MissingTranslationLog('k', 'm', 'en', $at, $long);
        self::assertSame(1024, strlen((string) $e->getCallSite()));
        self::assertStringEndsWith('...', (string) $e->getCallSite());
    }

    public function testConstructorTruncatesLongRequestPath(): void
    {
        $longPath = '/' . str_repeat('q', 2100);
        $at       = new DateTimeImmutable('2026-01-01 00:00:00');
        $e        = new MissingTranslationLog('k', 'm', 'en', $at, null, null, null, $longPath);
        $path     = $e->getRequestPath();
        self::assertNotNull($path);
        self::assertSame(2048, strlen($path));
        self::assertStringEndsWith('...', $path);
    }
}
