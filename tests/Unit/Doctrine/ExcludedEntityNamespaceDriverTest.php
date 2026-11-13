<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Tests\Unit\Doctrine;

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\Persistence\Mapping\Driver\MappingDriver;
use Doctrine\Persistence\Mapping\Driver\MappingDriverChain;
use Doctrine\Persistence\Mapping\MappingException;
use Nowo\TranslationYamlToolsBundle\Doctrine\ExcludedEntityNamespaceDriver;
use Nowo\TranslationYamlToolsBundle\Entity\MissingTranslationLog;
use Nowo\TranslationYamlToolsBundle\Tests\Fixtures\Host\HostNote;
use Nowo\TranslationYamlToolsBundle\TextOverride\Entity\TranslationOverride;
use PHPUnit\Framework\TestCase;

final class ExcludedEntityNamespaceDriverTest extends TestCase
{
    public function testMapsNothing(): void
    {
        $driver = new ExcludedEntityNamespaceDriver();

        self::assertTrue($driver->isTransient(MissingTranslationLog::class));
        self::assertSame([], $driver->getAllClassNames());

        $this->expectException(MappingException::class);
        $this->expectExceptionMessage(MissingTranslationLog::class);
        $driver->loadMetadataForClass(MissingTranslationLog::class, new ClassMetadata(MissingTranslationLog::class));
    }

    /**
     * A chain asks its namespace drivers before its default driver: a default driver that claims every entity class
     * no longer claims the bundle entities.
     */
    public function testWinsOverTheDefaultDriverOfAChain(): void
    {
        $catchAll = $this->createStub(MappingDriver::class);
        $catchAll->method('isTransient')->willReturn(false);
        $chain = new MappingDriverChain();
        $chain->setDefaultDriver($catchAll);
        self::assertFalse($chain->isTransient(MissingTranslationLog::class));
        self::assertFalse($chain->isTransient(TranslationOverride::class));

        $chain->addDriver(new ExcludedEntityNamespaceDriver(), 'Nowo\TranslationYamlToolsBundle\Entity');
        $chain->addDriver(new ExcludedEntityNamespaceDriver(), 'Nowo\TranslationYamlToolsBundle\TextOverride\Entity');

        self::assertTrue($chain->isTransient(MissingTranslationLog::class));
        self::assertTrue($chain->isTransient(TranslationOverride::class));
        self::assertFalse($chain->isTransient(HostNote::class), 'other classes still go to the default driver');
    }
}
