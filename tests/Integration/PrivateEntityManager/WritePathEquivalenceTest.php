<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Tests\Integration\PrivateEntityManager;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\TranslationYamlToolsBundle\Entity\MissingTranslationLog;
use Nowo\TranslationYamlToolsBundle\Entity\MissingTranslationLogStatus;
use Nowo\TranslationYamlToolsBundle\Repository\MissingTranslationLogRepository;
use Nowo\TranslationYamlToolsBundle\Tests\Fixtures\Host\HostNote;
use Nowo\TranslationYamlToolsBundle\Tests\Kernel\PrivateEntityManagerTestKernel;
use Nowo\TranslationYamlToolsBundle\Tests\Support\Legacy\LegacyMissingTranslationLogRepository;
use Nowo\TranslationYamlToolsBundle\Tests\Support\Legacy\LegacyTranslationOverrideRepository;
use Nowo\TranslationYamlToolsBundle\Tests\Support\Legacy\LegacyTranslationOverrides;
use Nowo\TranslationYamlToolsBundle\TextOverride\Entity\TranslationOverride;
use Nowo\TranslationYamlToolsBundle\TextOverride\Html\TranslationOverrideHtmlSanitizerInterface;
use Nowo\TranslationYamlToolsBundle\TextOverride\TranslationOverrides;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\KernelInterface;

use function assert;
use function count;
use function ksort;
use function preg_match;

/**
 * Equivalence of the 1.18.0 write path with 1.17.1 (REQ-ARCH-001 rule 6 change): the same scenario of override and
 * missing-log reads and writes runs through.
 *
 * - the literal v1.17.1 classes ({@see LegacyTranslationOverrides}, {@see LegacyTranslationOverrideRepository},
 *   {@see LegacyMissingTranslationLogRepository}) on the application's registry (the old path),
 * - the bundle services with the private entity manager (zero config, 1.18.0),
 * - the bundle services with `doctrine.private_entity_manager: false` (deprecated opt-out),
 * - the bundle services with a dedicated `doctrine.entity_manager`,
 *
 * each on a fresh schema, and must read the same values and leave the same database rows. Only the old path and the
 * opt-out leave bundle rows managed by, or flush pending changes of, the application's manager.
 */
final class WritePathEquivalenceTest extends TestCase
{
    /** @var list<KernelInterface> */
    private array $kernels = [];

    protected function tearDown(): void
    {
        foreach ($this->kernels as $kernel) {
            $kernel->shutdown();
        }
        $this->kernels = [];
    }

    #[IgnoreDeprecations]
    public function testEveryWritePathReadsAndWritesTheSameRows(): void
    {
        $kernel    = $this->boot('private');
        $doctrine  = $this->get($kernel, 'doctrine', ManagerRegistry::class);
        $sanitizer = $this->get($kernel, TranslationOverrideHtmlSanitizerInterface::class, TranslationOverrideHtmlSanitizerInterface::class);

        $legacyOverrides = new LegacyTranslationOverrides(new LegacyTranslationOverrideRepository($doctrine), new ArrayAdapter(), $sanitizer);
        $legacy          = $this->scenario($kernel, $legacyOverrides, new LegacyMissingTranslationLogRepository($doctrine));
        self::assertGreaterThan(0, $legacy['host_bundle_rows'], 'The old path keeps bundle rows on the application manager.');
        self::assertSame(1, $legacy['host_rows'], 'The old path flushed the application\'s pending note.');

        $kernel  = $this->boot('private');
        $private = $this->scenario($kernel, $this->get($kernel, 'test.pem.overrides', TranslationOverrides::class), $this->get($kernel, 'test.pem.log_repository', MissingTranslationLogRepository::class));
        self::assertSame(0, $private['host_bundle_rows'], 'The private path never puts bundle rows on the application manager.');
        self::assertSame(0, $private['host_rows'], 'The private path never flushes the application\'s pending note.');

        $kernel = $this->boot('legacy');
        $optOut = $this->scenario($kernel, $this->get($kernel, 'test.pem.overrides', TranslationOverrides::class), $this->get($kernel, 'test.pem.log_repository', MissingTranslationLogRepository::class));
        self::assertSame($legacy['host_rows'], $optOut['host_rows'], 'private_entity_manager: false is the pre-1.18 behaviour.');

        $kernel    = $this->boot('dedicated');
        $dedicated = $this->scenario($kernel, $this->get($kernel, 'test.pem.overrides', TranslationOverrides::class), $this->get($kernel, 'test.pem.log_repository', MissingTranslationLogRepository::class));
        self::assertSame(0, $dedicated['host_rows']);

        foreach (['private' => $private, 'opt-out' => $optOut, 'dedicated' => $dedicated] as $path => $result) {
            self::assertSame($legacy['reads'], $result['reads'], $path . ': same values read as 1.17.1.');
            self::assertSame($legacy['overrides'], $result['overrides'], $path . ': same override rows as 1.17.1.');
            self::assertSame($legacy['missing_log'], $result['missing_log'], $path . ': same missing-log rows as 1.17.1.');
        }
        self::assertCount(3, $legacy['overrides']);
        self::assertCount(2, $legacy['missing_log']);
    }

    /**
     * @return array{reads: array<string, mixed>, overrides: list<array<string, mixed>>, missing_log: list<array<string, mixed>>, host_rows: int, host_bundle_rows: int}
     */
    private function scenario(KernelInterface $kernel, LegacyTranslationOverrides|TranslationOverrides $overrides, LegacyMissingTranslationLogRepository|MissingTranslationLogRepository $log): array
    {
        $doctrine      = $this->get($kernel, 'doctrine', ManagerRegistry::class);
        $host          = $this->get($kernel, 'test.pem.host', EntityManagerInterface::class);
        $bundleManager = $doctrine->getManagerForClass(TranslationOverride::class);
        assert($bundleManager instanceof EntityManagerInterface);
        foreach ($bundleManager === $host ? [$host] : [$host, $bundleManager] as $manager) {
            (new SchemaTool($manager))->createSchema($manager->getMetadataFactory()->getAllMetadata());
        }
        // The application has a pending change when the bundle writes (a form that also edits a host entity).
        $host->persist(new HostNote('pending'));

        $reads = [];
        $overrides->set('en', 'messages', 'site.greeting', 'Hi %name%');
        $overrides->set('es', 'messages', 'site.greeting', 'Hola <b>%name%</b><script>alert(1)</script>');
        $overrides->set('en', 'messages', 'site.topline.hours', ' Open ', false);
        $overrides->set('es', 'messages', 'site.topline.hours', 'Abierto', false);
        $overrides->flush();
        $overrides->set('en', 'messages', 'site.greeting', 'Hi %name%');
        $overrides->set('en', 'messages', 'site.greeting', 'Hey %name%', updatedBy: 'editor-1');
        $overrides->set('es', 'messages', 'site.topline.hours', null);
        $overrides->set('es', 'messages', 'site.unknown', '   ');
        $reads['all']   = $overrides->all();
        $reads['get']   = [$overrides->get('en', 'messages', 'site.greeting'), $overrides->get('es', 'messages', 'site.topline.hours')];
        $reads['clean'] = $overrides->clean(' <i>x</i> ');
        $overrides->reset();
        $reads['reloaded'] = $overrides->all();

        $log->persistBuffer([
            'a' => ['hits' => 2, 'messageId' => 'missing.one', 'domain' => 'messages', 'locale' => 'en', 'requestRoute' => 'home', 'requestMethod' => 'get', 'requestPath' => '/'],
            'b' => ['hits' => 1, 'messageId' => 'missing.two', 'domain' => 'messages', 'locale' => 'es'],
            'c' => ['hits' => 1, 'messageId' => 'missing.three', 'domain' => 'admin', 'locale' => 'en'],
        ]);
        $log->persistBuffer(['a' => ['hits' => 3, 'messageId' => 'missing.one', 'domain' => 'messages', 'locale' => 'en', 'callSite' => 'src/A.php:1']]);
        // Rows seen in the same second keep the id order; across seconds the newest first: compare by message id.
        $ids              = [];
        $reads['pending'] = [];
        foreach ($log->findByStatus(MissingTranslationLogStatus::Pending) as $row) {
            $ids[$row->getMessageId()]              = (int) $row->getId();
            $reads['pending'][$row->getMessageId()] = $row->getHitCount();
        }
        ksort($reads['pending']);
        $two = $log->findOneById($ids['missing.two']);
        assert($two instanceof MissingTranslationLog);
        $two->setStatus(MissingTranslationLogStatus::Added);
        $two->setNotes('done');
        $log->flush();
        $three = $log->findOneById($ids['missing.three']);
        assert($three instanceof MissingTranslationLog);
        $three->setStatus(MissingTranslationLogStatus::Validated);
        $log->flush();
        $reads['counts']  = [$log->countByStatus(MissingTranslationLogStatus::Pending), $log->countByStatus(MissingTranslationLogStatus::Added)];
        $reads['cleared'] = $log->clearByStatus(MissingTranslationLogStatus::Validated);
        $reads['missing'] = !$log->findOneById(999) instanceof MissingTranslationLog;

        $hostBundleRows = 0;
        foreach ($host->getUnitOfWork()->getIdentityMap() as $class => $entities) {
            if ($class === TranslationOverride::class || $class === MissingTranslationLog::class) {
                $hostBundleRows += count($entities);
            }
        }

        $bundle = $bundleManager->getConnection();

        return [
            'reads'            => $reads,
            'overrides'        => $this->rows($bundle, 'SELECT * FROM nowo_translation_override ORDER BY id'),
            'missing_log'      => $this->rows($bundle, 'SELECT * FROM nowo_translation_missing_log ORDER BY id'),
            'host_rows'        => (int) $host->getConnection()->fetchOne('SELECT COUNT(*) FROM host_note'),
            'host_bundle_rows' => $hostBundleRows,
        ];
    }

    /**
     * Every column; a timestamp column (wall clock) is compared as set / not set.
     *
     * @return list<array<string, mixed>>
     */
    private function rows(Connection $connection, string $sql): array
    {
        $rows = [];
        foreach ($connection->fetchAllAssociative($sql) as $row) {
            foreach ($row as $column => $value) {
                if (preg_match('/(_at|At)$/', $column) === 1) {
                    $row[$column] = $value !== null;
                }
            }
            $rows[] = $row;
        }

        return $rows;
    }

    private function boot(string $variant): KernelInterface
    {
        $kernel = new PrivateEntityManagerTestKernel('test', false, $variant);
        $kernel->boot();
        $this->kernels[] = $kernel;

        return $kernel;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function get(KernelInterface $kernel, string $id, string $class): object
    {
        $container = $kernel->getContainer()->has('test.service_container') ? $kernel->getContainer()->get('test.service_container') : $kernel->getContainer();
        assert($container instanceof ContainerInterface);
        $service = $container->get($id);
        self::assertInstanceOf($class, $service);

        return $service;
    }
}
