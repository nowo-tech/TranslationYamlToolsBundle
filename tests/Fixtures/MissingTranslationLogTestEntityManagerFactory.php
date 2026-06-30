<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Tests\Fixtures;

use Doctrine\Common\EventManager;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Events;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\TranslationYamlToolsBundle\Doctrine\MissingTranslationLogMetadataListener;
use Nowo\TranslationYamlToolsBundle\Entity\MissingTranslationLog;
use Nowo\TranslationYamlToolsBundle\Repository\MissingTranslationLogRepository;
use PHPUnit\Framework\TestCase;

/**
 * Builds an in-memory SQLite EntityManager for missing-log unit tests.
 *
 * On PHP 8.4+, Doctrine ORM prefers native lazy objects. Symfony 8's var-exporter
 * no longer exposes LazyGhost, so native lazy objects are required there.
 */
final class MissingTranslationLogTestEntityManagerFactory
{
    public static function createRepository(
        string $tablePrefix = 'nowo_translation_',
    ): MissingTranslationLogRepository {
        $entityDir = dirname(__DIR__, 2) . '/src/Entity';
        $config    = ORMSetup::createAttributeMetadataConfiguration([$entityDir], true);
        if (PHP_VERSION_ID >= 80400) {
            $config->enableNativeLazyObjects(true);
        }

        $evm = new EventManager();
        $evm->addEventListener(
            Events::loadClassMetadata,
            new MissingTranslationLogMetadataListener($tablePrefix),
        );

        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'path'   => ':memory:',
        ]);
        $em = new EntityManager($connection, $config, $evm);

        $tool = new SchemaTool($em);
        $tool->createSchema([$em->getClassMetadata(MissingTranslationLog::class)]);

        $registry = (new class ('manager_registry_helper') extends TestCase {
            public function createManagerRegistry(EntityManager $em): ManagerRegistry
            {
                $registry = $this->createMock(ManagerRegistry::class);
                $registry->method('getManagerForClass')->with(MissingTranslationLog::class)->willReturn($em);
                $registry->method('getManager')->willReturn($em);

                return $registry;
            }
        })->createManagerRegistry($em);

        return new MissingTranslationLogRepository($registry);
    }
}
