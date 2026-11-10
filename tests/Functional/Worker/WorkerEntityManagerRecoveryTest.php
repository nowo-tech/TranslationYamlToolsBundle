<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Tests\Functional\Worker;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\TranslationYamlToolsBundle\Tests\Kernel\WorkerTextOverrideTestKernel;
use Nowo\TranslationYamlToolsBundle\TextOverride\Entity\TranslationOverride;
use Nowo\TranslationYamlToolsBundle\TextOverride\Repository\TranslationOverrideRepository;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;
use Throwable;

/**
 * FrankenPHP worker mode with the kernel kept alive and never reset (FRANKENPHP_RESET_KERNEL=false, no services
 * resetter): one booted kernel serves every request through the http_kernel service, so kernel.reset never runs.
 * The override entity lives on its own manager ("texts", doctrine.entity_manager). When a failed flush closes it, the
 * bundle reopens that manager by name; the host's closed default manager is never touched (before 1.16.1 the bundle
 * called resetManager() without a name, i.e. reset the host's default manager and left its own closed).
 */
final class WorkerEntityManagerRecoveryTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return WorkerTextOverrideTestKernel::class;
    }

    public function testBundleManagerRecoversAcrossRequestsAndHostManagerIsUntouched(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $doctrine  = $container->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $doctrine);
        $host  = $this->manager($doctrine, 'default');
        $texts = $this->manager($doctrine, 'texts');
        self::assertSame($texts, $doctrine->getManagerForClass(TranslationOverride::class), 'doctrine.entity_manager maps the entity on "texts".');
        (new SchemaTool($texts))->createSchema([$texts->getClassMetadata(TranslationOverride::class)]);
        $repository = $container->get(TranslationOverrideRepository::class);
        self::assertInstanceOf(TranslationOverrideRepository::class, $repository);
        $http = $container->get('http_kernel');
        self::assertInstanceOf(HttpKernelInterface::class, $http);

        $host->close();
        for ($i = 0; $i < 20; ++$i) {
            // A failed flush of the previous request (duplicate key) closes the bundle manager.
            $repository->persist(new TranslationOverride('en', 'messages', 'site.dup.' . $i));
            $repository->persist(new TranslationOverride('en', 'messages', 'site.dup.' . $i));
            try {
                $repository->flush();
                self::fail('A duplicate override must fail.');
            } catch (Throwable) {
            }
            self::assertFalse($texts->isOpen());

            $this->serve($http);
            self::assertNull($repository->findOne('en', 'messages', 'site.dup.' . $i), 'The next call reopens the bundle manager.');
            self::assertTrue($texts->isOpen());
            self::assertFalse($host->isOpen(), 'The bundle must never reset the host manager.');
        }
    }

    private function manager(ManagerRegistry $doctrine, string $name): EntityManagerInterface
    {
        $manager = $doctrine->getManager($name);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);

        return $manager;
    }

    private function serve(HttpKernelInterface $http): void
    {
        $request  = Request::create('/_translation_yaml_tools/texts/');
        $response = $http->handle($request);
        self::assertSame(200, $response->getStatusCode());
        if ($http instanceof TerminableInterface) {
            $http->terminate($request, $response);
        }
    }
}
