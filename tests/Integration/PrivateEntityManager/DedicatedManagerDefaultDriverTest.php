<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Tests\Integration\PrivateEntityManager;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\TranslationYamlToolsBundle\Entity\MissingTranslationLog;
use Nowo\TranslationYamlToolsBundle\Repository\MissingTranslationLogRepository;
use Nowo\TranslationYamlToolsBundle\Tests\Kernel\DedicatedManagerDefaultDriverTestKernel;
use Nowo\TranslationYamlToolsBundle\TextOverride\Entity\TranslationOverride;
use Nowo\TranslationYamlToolsBundle\TextOverride\Repository\TranslationOverrideRepository;
use Nowo\TranslationYamlToolsBundle\TextOverride\TranslationOverrideEditor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;

use function array_keys;
use function assert;

/**
 * DoctrineBundle 3.4 regression: `auto_discover_entities` (on by default for a manager without `mappings` and
 * `auto_mapping`) gives the host manager's chain a default attribute driver, which claims every `#[ORM\Entity]` class.
 * With `doctrine.entity_manager` set, the host manager mapped the bundle entities too, and once it came first in the
 * registry `getManagerForClass()` answered it: the bundle repositories (and any host code going through `doctrine`)
 * queried the host database ("no such table"). The explicit catch-all default driver reproduces it on every
 * DoctrineBundle version.
 */
final class DedicatedManagerDefaultDriverTest extends TestCase
{
    private ?DedicatedManagerDefaultDriverTestKernel $kernel = null;

    protected function tearDown(): void
    {
        $this->kernel?->shutdown();
        $this->kernel = null;
    }

    #[DataProvider('hostManagersWithADefaultDriver')]
    public function testTheDedicatedManagerWinsOverADefaultDriverOfTheHostManager(bool $explicitDefaultDriver, bool $hostManagerFirst): void
    {
        $this->kernel = new DedicatedManagerDefaultDriverTestKernel($explicitDefaultDriver, $hostManagerFirst);
        $this->kernel->boot();
        $registry = $this->get('doctrine', ManagerRegistry::class);
        self::assertSame($hostManagerFirst ? ['default', 'texts'] : ['texts', 'default'], array_keys($registry->getManagerNames()));
        $bundle = $registry->getManager('texts');

        // Neither manager is initialized yet: DoctrineBundle 3.4 asks the metadata drivers.
        self::assertSame($bundle, $registry->getManagerForClass(TranslationOverride::class));
        self::assertSame($bundle, $registry->getManagerForClass(MissingTranslationLog::class));

        $host = $registry->getManager('default');
        assert($host instanceof EntityManagerInterface && $bundle instanceof EntityManagerInterface);
        (new SchemaTool($bundle))->createSchema($bundle->getMetadataFactory()->getAllMetadata());
        self::assertTrue($host->getMetadataFactory()->isTransient(TranslationOverride::class), 'not mapped on the host manager');
        self::assertTrue($host->getMetadataFactory()->isTransient(MissingTranslationLog::class), 'not mapped on the host manager');
        self::assertSame($bundle, $registry->getManagerForClass(TranslationOverride::class));

        // Real bundle writes and reads go to the bundle database.
        $this->get('test.dd.editor', TranslationOverrideEditor::class)->save('messages', 'site.greeting', ['en' => 'Hi %name%'], updatedBy: 'editor');
        $this->get('test.dd.log_repository', MissingTranslationLogRepository::class)->persistBuffer([
            'a' => ['hits' => 1, 'messageId' => 'missing.one', 'domain' => 'messages', 'locale' => 'en'],
        ]);
        $bundle->clear();
        self::assertSame('Hi %name%', $this->get('test.dd.override_repository', TranslationOverrideRepository::class)->findOne('en', 'messages', 'site.greeting')?->getValue());
        self::assertSame(1, (int) $bundle->getConnection()->fetchOne('SELECT COUNT(*) FROM ' . $bundle->getClassMetadata(MissingTranslationLog::class)->getTableName()));
        self::assertSame([], $host->getConnection()->createSchemaManager()->listTableNames(), 'nothing on the host database');
    }

    /**
     * @return iterable<string, array{bool, bool}>
     */
    public static function hostManagersWithADefaultDriver(): iterable
    {
        yield 'host manager without mappings (DoctrineBundle 3.4 auto-discovery)' => [false, false];
        yield 'host manager without mappings, first in the registry' => [false, true];
        yield 'host manager with a catch-all default driver' => [true, false];
        yield 'host manager with a catch-all default driver, first in the registry' => [true, true];
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function get(string $id, string $class): object
    {
        assert($this->kernel instanceof DedicatedManagerDefaultDriverTestKernel);
        $container = $this->kernel->getContainer()->get('test.service_container');
        assert($container instanceof ContainerInterface);
        $service = $container->get($id);
        self::assertInstanceOf($class, $service);

        return $service;
    }
}
