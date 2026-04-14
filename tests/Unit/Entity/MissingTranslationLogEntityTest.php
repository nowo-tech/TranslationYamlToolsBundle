<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Tests\Unit\Entity;

use DateTimeImmutable;
use Nowo\TranslationYamlToolsBundle\Entity\MissingTranslationLog;
use Nowo\TranslationYamlToolsBundle\Entity\MissingTranslationLogStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

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
    }

    public function testRegisterAdditionalHitsSkipsWhenHitsBelowOne(): void
    {
        $at = new DateTimeImmutable('2026-01-02 12:00:00');
        $e  = new MissingTranslationLog('a', 'm', 'en', $at);
        $before = $e->getHitCount();
        $e->registerAdditionalHits(0, $at);
        self::assertSame($before, $e->getHitCount());
    }

    public function testRegisterAdditionalHitsAndSetters(): void
    {
        $t0 = new DateTimeImmutable('2026-01-01 12:00:00');
        $t1 = new DateTimeImmutable('2026-01-03 15:00:00');
        $e  = new MissingTranslationLog('x', 'd', 'fr', $t0);

        $e->registerAdditionalHits(2, $t1, '/other.php:2');
        self::assertSame(3, $e->getHitCount());
        self::assertSame($t1, $e->getLastSeenAt());
        self::assertSame('/other.php:2', $e->getCallSite());

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
}
