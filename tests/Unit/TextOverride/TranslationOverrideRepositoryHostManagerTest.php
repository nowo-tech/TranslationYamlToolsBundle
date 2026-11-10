<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Tests\Unit\TextOverride;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\TranslationYamlToolsBundle\Tests\Support\SqliteManagerRegistry;
use Nowo\TranslationYamlToolsBundle\TextOverride\Entity\TranslationOverride;
use Nowo\TranslationYamlToolsBundle\TextOverride\Repository\TranslationOverrideRepository;
use PHPUnit\Framework\TestCase;

use function dirname;

/**
 * Rule 6 (W0): a closed override manager is reset by name; the host's default manager is never reset by the bundle.
 * Real ORM: two in-memory SQLite entity managers behind a real ManagerRegistry.
 */
final class TranslationOverrideRepositoryHostManagerTest extends TestCase
{
    private const BUNDLE = ['Nowo\\TranslationYamlToolsBundle\\TextOverride\\Entity' => __DIR__ . '/../../../src/TextOverride/Entity'];

    public function testClosedOverrideManagerIsResetByNameAndHostManagerIsUntouched(): void
    {
        $registry = new SqliteManagerRegistry(['default' => ['App\\Entity' => dirname(__DIR__, 2)], 'texts' => self::BUNDLE]);
        $host     = $this->manager($registry, 'default');
        $host->close();
        $texts = $this->manager($registry, 'texts');
        (new SchemaTool($texts))->createSchema([$texts->getClassMetadata(TranslationOverride::class)]);
        $texts->close();

        self::assertSame([], (new TranslationOverrideRepository($registry))->loadMap());

        self::assertSame($host, $registry->getManager('default'), 'The bundle must never reset the host manager.');
        self::assertFalse($host->isOpen());
        self::assertTrue($this->manager($registry, 'texts')->isOpen());
    }

    public function testDefaultOnlyHostGetsItsManagerBack(): void
    {
        $registry = new SqliteManagerRegistry(['default' => self::BUNDLE]);
        $manager  = $this->manager($registry, 'default');
        (new SchemaTool($manager))->createSchema([$manager->getClassMetadata(TranslationOverride::class)]);
        $manager->close();

        (new TranslationOverrideRepository($registry))->flush();

        self::assertTrue($this->manager($registry, 'default')->isOpen());
    }

    private function manager(SqliteManagerRegistry $registry, string $name): EntityManagerInterface
    {
        $manager = $registry->getManager($name);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);

        return $manager;
    }
}
