<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Tests\Kernel;

use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Symfony\Contracts\Translation\TranslatorInterface;

use function assert;
use function sys_get_temp_dir;

/**
 * Kernel of the kept-alive boundary tests (REQ-RUNTIME-002): the text-override fixture app on an in-memory SQLite
 * database, with one page ("/greet/{tenant}") that runs {@see self::unitOfWork()}, the same unit the Messenger handler
 * and the console command of the test run. Tenant A reads the English text, tenant B the Spanish one.
 */
final class WorkerBoundariesTestKernel extends Kernel
{
    use MicroKernelTrait;

    public const TRANSLATOR = 'test.boundaries.translator';
    public const CACHE      = 'test.boundaries.overrides_cache';

    public const KEY = 'site.greeting';

    public function getProjectDir(): string
    {
        return __DIR__ . '/../Fixtures/app_text_override';
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir() . '/nowo-translation-yaml-tools-boundaries/cache/' . $this->environment;
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir() . '/nowo-translation-yaml-tools-boundaries/log';
    }

    public static function localeOf(string $tenant): string
    {
        return $tenant === 'A' ? 'en' : 'es';
    }

    /**
     * One unit of work for a tenant: the greeting as the tenant's locale renders it (override or catalogue text).
     */
    public static function unitOfWork(TranslatorInterface $translator, string $tenant): string
    {
        return $translator->trans(self::KEY, ['%name%' => $tenant], 'messages', self::localeOf($tenant));
    }

    public function greet(string $tenant): Response
    {
        $translator = $this->getContainer()->get(self::TRANSLATOR);
        assert($translator instanceof TranslatorInterface);

        return new Response(self::unitOfWork($translator, $tenant));
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $dir = $this->getProjectDir() . '/config/packages/';
        $container->import($dir . 'framework.yaml');
        $container->import($dir . 'twig.yaml');
        // Web UI off: the boundary tests drive the translator, not the admin.
        $container->extension('nowo_translation_yaml_tools', ['overrides' => ['enabled' => true, 'editable' => ['messages' => ['site.']]]]);
        $container->extension('doctrine', [
            'dbal' => ['url' => 'sqlite:///:memory:'],
            'orm'  => ['auto_mapping' => true],
        ]);
        $container->services()->set('logger', NullLogger::class);
        $container->services()->alias(self::TRANSLATOR, 'translator')->public();
        $container->services()->alias(self::CACHE, 'nowo_translation_yaml_tools.overrides.cache')->public();
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->add('test_greet', '/greet/{tenant}')->controller('kernel::greet');
    }
}
