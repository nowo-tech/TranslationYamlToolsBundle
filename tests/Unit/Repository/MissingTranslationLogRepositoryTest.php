<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Tests\Unit\Repository;

use Doctrine\Common\EventManager;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Events;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\TranslationYamlToolsBundle\Doctrine\MissingTranslationLogMetadataListener;
use Nowo\TranslationYamlToolsBundle\Entity\MissingTranslationLog;
use Nowo\TranslationYamlToolsBundle\Entity\MissingTranslationLogStatus;
use Nowo\TranslationYamlToolsBundle\Repository\MissingTranslationLogRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MissingTranslationLogRepository::class)]
final class MissingTranslationLogRepositoryTest extends TestCase
{
    private function createRepository(): MissingTranslationLogRepository
    {
        $entityDir = dirname(__DIR__, 3) . '/src/Entity';
        $config     = ORMSetup::createAttributeMetadataConfiguration([$entityDir], true);
        $evm        = new EventManager();
        $evm->addEventListener(Events::loadClassMetadata, new MissingTranslationLogMetadataListener('nowo_translation_'));

        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'path'   => ':memory:',
        ]);
        $em = new EntityManager($connection, $config, $evm);

        $tool = new SchemaTool($em);
        $tool->createSchema([$em->getClassMetadata(MissingTranslationLog::class)]);

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->with(MissingTranslationLog::class)->willReturn($em);
        $registry->method('getManager')->willReturn($em);

        return new MissingTranslationLogRepository($registry);
    }

    public function testPersistBufferEmptyIsNoOp(): void
    {
        $repo = $this->createRepository();
        $repo->persistBuffer([]);
        self::assertSame(0, $repo->count([]));
    }

    public function testPersistBufferInsertAndDuplicateUpdatesHits(): void
    {
        $repo = $this->createRepository();
        $repo->persistBuffer([
            'a' => [
                'hits'      => 1,
                'messageId' => 'k1',
                'domain'    => 'messages',
                'locale'    => 'es',
                'callSite'  => null,
            ],
        ]);
        $repo->persistBuffer([
            'a' => [
                'hits'      => 2,
                'messageId' => 'k1',
                'domain'    => 'messages',
                'locale'    => 'es',
                'callSite'  => '/tmp/x.php:1',
            ],
        ]);

        $rows = $repo->findByStatus(MissingTranslationLogStatus::Pending, 10);
        self::assertCount(1, $rows);
        self::assertSame(3, $rows[0]->getHitCount());
        self::assertSame('/tmp/x.php:1', $rows[0]->getCallSite());
    }

    public function testFindByStatusAndFindOneById(): void
    {
        $repo = $this->createRepository();
        $repo->persistBuffer([
            'a' => [
                'hits'      => 1,
                'messageId' => 'id.a',
                'domain'    => 'd',
                'locale'    => 'en',
                'callSite'  => null,
            ],
        ]);
        $id = $repo->findByStatus(MissingTranslationLogStatus::Pending, 5)[0]->getId();
        self::assertNotNull($id);
        $one = $repo->findOneById($id);
        self::assertNotNull($one);
        self::assertSame('id.a', $one->getMessageId());
    }

    public function testPersistBufferTruncatesLongCallSite(): void
    {
        $long = str_repeat('x', 1100);
        $repo = $this->createRepository();
        $repo->persistBuffer([
            'a' => [
                'hits'      => 1,
                'messageId' => 'k',
                'domain'    => 'm',
                'locale'    => 'es',
                'callSite'  => $long,
            ],
        ]);
        $site = $repo->findByStatus(MissingTranslationLogStatus::Pending, 1)[0]->getCallSite();
        self::assertNotNull($site);
        self::assertSame(1024, strlen($site));
        self::assertStringEndsWith('...', $site);
    }
}
