<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Tests\Unit\TextOverride;

use ArrayObject;
use Doctrine\Common\EventManager;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Events;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Nowo\TranslationYamlToolsBundle\TextOverride\Doctrine\TranslationOverrideMetadataListener;
use Nowo\TranslationYamlToolsBundle\TextOverride\Entity\TranslationOverride;
use Nowo\TranslationYamlToolsBundle\TextOverride\Repository\TranslationOverrideRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function dirname;

use const PHP_VERSION_ID;

#[CoversClass(TranslationOverrideRepository::class)]
final class TranslationOverrideRepositoryTest extends TestCase
{
    public function testPersistLoadFindAndRemove(): void
    {
        $em         = $this->entityManager();
        $repository = new TranslationOverrideRepository($this->registry($em));

        $repository->persist((new TranslationOverride('es', 'messages', 'site.a'))->setValue('A'));
        $repository->persist((new TranslationOverride('en', 'Shop', 'loader.x'))->setValue('X'));
        $repository->flush();

        self::assertSame(['es' => ['messages' => ['site.a' => 'A']], 'en' => ['Shop' => ['loader.x' => 'X']]], $repository->loadMap());
        $row = $repository->findOne('es', 'messages', 'site.a');
        self::assertInstanceOf(TranslationOverride::class, $row);
        self::assertNull($repository->findOne('en', 'messages', 'site.a'));

        $repository->delete($row);
        $repository->flush();
        self::assertSame(['en' => ['Shop' => ['loader.x' => 'X']]], $repository->loadMap());
    }

    public function testReopensClosedEntityManager(): void
    {
        $closed = $this->entityManager();
        $closed->close();

        self::assertSame([], (new TranslationOverrideRepository($this->resettingRegistry($closed, $this->entityManager())))->loadMap());
    }

    public function testFailsWithoutEntityManager(): void
    {
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn(null);

        $this->expectException(LogicException::class);
        (new TranslationOverrideRepository($registry))->loadMap();
    }

    public function testFailsWhenResetManagerYieldsNothing(): void
    {
        $closed = $this->entityManager();
        $closed->close();

        $this->expectException(LogicException::class);
        (new TranslationOverrideRepository($this->resettingRegistry($closed, null)))->flush();
    }

    private function resettingRegistry(EntityManager $before, ?EntityManager $after): ManagerRegistry
    {
        $state    = new ArrayObject(['reset' => false]);
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturnCallback(
            static fn (): ?EntityManager => $state['reset'] === true ? $after : $before,
        );
        $registry->expects(self::once())->method('resetManager')->willReturnCallback(static function () use ($state, $before): EntityManager {
            $state['reset'] = true;

            return $before;
        });

        return $registry;
    }

    private function entityManager(): EntityManager
    {
        $config = ORMSetup::createAttributeMetadataConfiguration([dirname(__DIR__, 3) . '/src/TextOverride/Entity'], true);
        if (PHP_VERSION_ID >= 80400) {
            $config->enableNativeLazyObjects(true);
        }
        $evm = new EventManager();
        $evm->addEventListener(Events::loadClassMetadata, new TranslationOverrideMetadataListener('nowo_translation_'));

        $em = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => ':memory:']), $config, $evm);
        (new SchemaTool($em))->createSchema([$em->getClassMetadata(TranslationOverride::class)]);

        return $em;
    }

    private function registry(EntityManager $em): ManagerRegistry
    {
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($em);

        return $registry;
    }
}
