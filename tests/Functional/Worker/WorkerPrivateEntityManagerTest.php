<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Tests\Functional\Worker;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\TranslationYamlToolsBundle\Doctrine\TranslationYamlToolsEntityManager;
use Nowo\TranslationYamlToolsBundle\Entity\MissingTranslationLog;
use Nowo\TranslationYamlToolsBundle\Tests\Fixtures\Host\HostNote;
use Nowo\TranslationYamlToolsBundle\Tests\Kernel\PrivateEntityManagerTestKernel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;

use function array_key_first;
use function array_map;
use function gc_collect_cycles;
use function memory_get_usage;
use function sprintf;

/**
 * FrankenPHP worker mode with the kernel kept alive and the services resetter never called (REQ-RUNTIME-002): one
 * booted kernel, every request through the http_kernel service (no Kernel::handle(), so no services_resetter), two
 * locales ("users") in alternation, 1.18.0 private entity manager (zero config).
 *
 * Every iteration: request 1 stages a host note on the application's manager (the host never flushes it) and saves the
 * greeting override of its locale through the editor; request 2 reads the other locale's greeting. Asserts per
 * iteration: each response holds only its locale's latest text; the application's pending insert is still pending
 * and no host row was written (the bundle never flushed the host's identity map); the private manager is dropped at
 * kernel.terminate; the host then clears its own manager (REQ-RUNTIME-002 rule 7). The missing-translation log is
 * persisted through the private manager's connection at terminate: one hit per request and locale.
 *
 * N = 400 iterations (800 requests). Memory margin: 1 MiB after a 50-iteration warm-up.
 */
final class WorkerPrivateEntityManagerTest extends KernelTestCase
{
    private const ITERATIONS    = 400;
    private const WARMUP        = 50;
    private const MEMORY_MARGIN = 1024 * 1024;

    protected static function getKernelClass(): string
    {
        return PrivateEntityManagerTestKernel::class;
    }

    public function testManyRequestsAlternatingLocalesNeverFlushTheHostManager(): void
    {
        self::bootKernel(['debug' => false]);
        $container = self::getContainer();
        $host      = $container->get('test.pem.host');
        self::assertInstanceOf(EntityManagerInterface::class, $host);
        $connection = $host->getConnection();
        (new SchemaTool($host))->createSchema($host->getMetadataFactory()->getAllMetadata());
        $private = $container->get('test.pem.private');
        self::assertInstanceOf(TranslationYamlToolsEntityManager::class, $private);
        $http = $container->get('http_kernel');
        self::assertInstanceOf(HttpKernelInterface::class, $http);
        self::assertInstanceOf(TerminableInterface::class, $http);

        $leaks    = [];
        $baseline = 0;
        $latest   = ['en' => 'Hello en', 'es' => 'Hola es'];
        for ($i = 0; $i < self::ITERATIONS; ++$i) {
            $locale = $i % 2 === 0 ? 'en' : 'es';
            $other  = $locale === 'en' ? 'es' : 'en';
            $text   = sprintf('%s %%name%% #%d', $locale === 'en' ? 'Hi' : 'Hola', $i);

            $written         = $this->serve($http, Request::create('/edit/' . $locale, 'GET', ['text' => $text]));
            $latest[$locale] = sprintf('%s %s #%d', $locale === 'en' ? 'Hi' : 'Hola', $locale, $i);
            if ($written !== $latest[$locale]) {
                $leaks[] = sprintf('iteration %d (%s) wrote and read "%s"', $i, $locale, $written);
            }
            $read = $this->serve($http, Request::create('/greet/' . $other));
            if ($read !== $latest[$other]) {
                $leaks[] = sprintf('iteration %d (%s) read "%s"', $i, $other, $read);
            }

            $pending = $host->getUnitOfWork()->getScheduledEntityInsertions();
            self::assertCount(1, $pending, 'The host note of this iteration is still pending: the bundle never flushed the host manager.');
            self::assertInstanceOf(HostNote::class, $pending[array_key_first($pending)]);
            self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM host_note'));
            self::assertFalse($private->isCreated(), 'kernel.terminate dropped the private manager.');
            // REQ-RUNTIME-002 rule 7: the host clears its own manager at its boundary.
            $host->clear();

            if ($i === self::WARMUP) {
                gc_collect_cycles();
                $baseline = memory_get_usage();
            }
        }
        gc_collect_cycles();

        self::assertSame([], $leaks, 'Stale or foreign override text between requests.');
        self::assertSame(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM nowo_translation_override'));
        $hitCount = $host->getClassMetadata(MissingTranslationLog::class)->getColumnName('hitCount');
        $hits     = $connection->fetchFirstColumn(sprintf('SELECT %s FROM nowo_translation_missing_log ORDER BY locale', $hitCount));
        self::assertSame([self::ITERATIONS, self::ITERATIONS], array_map('intval', $hits), 'One missing-key hit per request and locale.');
        self::assertTrue($host->isOpen());
        self::assertLessThan(self::MEMORY_MARGIN, memory_get_usage() - $baseline, 'Memory grew across requests.');
    }

    private function serve(HttpKernelInterface&TerminableInterface $http, Request $request): string
    {
        $response = $http->handle($request);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $http->terminate($request, $response);

        return (string) $response->getContent();
    }
}
