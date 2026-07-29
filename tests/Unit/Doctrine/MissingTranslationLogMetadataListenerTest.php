<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Tests\Unit\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\Mapping\ClassMetadata;
use Nowo\TranslationYamlToolsBundle\Doctrine\MissingTranslationLogMetadataListener;
use Nowo\TranslationYamlToolsBundle\Entity\MissingTranslationLog;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use stdClass;

#[CoversClass(MissingTranslationLogMetadataListener::class)]
final class MissingTranslationLogMetadataListenerTest extends TestCase
{
    public function testLoadClassMetadataIgnoresOtherEntities(): void
    {
        $listener = new MissingTranslationLogMetadataListener('pfx_');
        $metadata = $this->createMock(ClassMetadata::class);
        $metadata->method('getName')->willReturn(stdClass::class);
        $args = new LoadClassMetadataEventArgs($metadata, $this->createMock(EntityManagerInterface::class));

        $listener->loadClassMetadata($args);

        $metadata->expects(self::never())->method('setPrimaryTable');
    }

    public function testLoadClassMetadataAppliesTableAndConstraints(): void
    {
        $listener = new MissingTranslationLogMetadataListener('acme_');
        $metadata = new ClassMetadata(MissingTranslationLog::class);
        $args     = new LoadClassMetadataEventArgs($metadata, $this->createMock(EntityManagerInterface::class));

        $listener->loadClassMetadata($args);

        self::assertSame('acme_missing_log', $metadata->getTableName());
        $table = $metadata->table;
        self::assertArrayHasKey('uniqueConstraints', $table);
        self::assertArrayHasKey('indexes', $table);
    }
}
