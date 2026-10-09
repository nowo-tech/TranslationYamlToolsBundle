<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Tests\Unit\TextOverride;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\Mapping\ClassMetadata;
use Nowo\TranslationYamlToolsBundle\TextOverride\Doctrine\TranslationOverrideMetadataListener;
use Nowo\TranslationYamlToolsBundle\TextOverride\Entity\TranslationOverride;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use stdClass;

#[CoversClass(TranslationOverride::class)]
#[CoversClass(TranslationOverrideMetadataListener::class)]
final class TranslationOverrideEntityAndMetadataTest extends TestCase
{
    public function testEntityAccessors(): void
    {
        $created = new DateTimeImmutable('2026-01-01 10:00:00');
        $updated = new DateTimeImmutable('2026-01-02 10:00:00');
        $row     = new TranslationOverride('es', 'messages', 'site.a', $created);

        self::assertNull($row->getId());
        self::assertSame('es', $row->getLocale());
        self::assertSame('messages', $row->getDomain());
        self::assertSame('site.a', $row->getMessageKey());
        self::assertSame('', $row->getValue());
        self::assertSame($created, $row->getCreatedAt());
        self::assertSame($created, $row->getUpdatedAt());
        self::assertNull($row->getUpdatedBy());

        $row->setValue('Hola', str_repeat('u', 200), $updated);
        self::assertSame('Hola', $row->getValue());
        self::assertSame($updated, $row->getUpdatedAt());
        self::assertSame(180, mb_strlen((string) $row->getUpdatedBy()));
        self::assertNull($row->setValue('x', '')->getUpdatedBy());
    }

    public function testMetadataListenerSetsPrefixedTableAndUniqueConstraint(): void
    {
        $metadata = new ClassMetadata(TranslationOverride::class);
        (new TranslationOverrideMetadataListener('app_'))->loadClassMetadata(
            new LoadClassMetadataEventArgs($metadata, $this->createStub(EntityManagerInterface::class)),
        );

        self::assertSame('app_override', $metadata->getTableName());
        $constraints = $metadata->table['uniqueConstraints'] ?? [];
        self::assertCount(1, $constraints);
        self::assertSame(['locale', 'domain', 'message_key'], array_values($constraints)[0]['columns']);

        $other = $this->createMock(ClassMetadata::class);
        $other->method('getName')->willReturn(stdClass::class);
        $other->expects(self::never())->method('setPrimaryTable');
        (new TranslationOverrideMetadataListener('app_'))->loadClassMetadata(
            new LoadClassMetadataEventArgs($other, $this->createStub(EntityManagerInterface::class)),
        );
    }
}
