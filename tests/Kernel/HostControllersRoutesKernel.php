<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Tests\Kernel;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Nowo\FormKitBundle\NowoFormKitBundle;
use Nowo\TranslationYamlToolsBundle\NowoTranslationYamlToolsBundle;
use Nowo\UiKitBundle\NowoUiKitBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

use function dirname;
use function sys_get_temp_dir;

/**
 * Kernel with both web UIs enabled whose routing is only the Symfony default
 * `controllers: { resource: routing.controllers }` (no import of the bundle route files): the bundle routes must not
 * be registered there (REQ-SF-004 rule 7).
 */
final class HostControllersRoutesKernel extends Kernel
{
    use MicroKernelTrait;

    public const CACHE_ROOT = '/nowo-translation-yaml-tools-host-controllers';

    public function __construct()
    {
        // Debug kernel: allow_unauthenticated (no SecurityBundle here) is debug-only.
        parent::__construct('test', true);
    }

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new DoctrineBundle();
        yield new TwigBundle();
        yield new NowoFormKitBundle();
        yield new NowoUiKitBundle();
        yield new NowoTranslationYamlToolsBundle();
    }

    public function getProjectDir(): string
    {
        return dirname(__DIR__, 2);
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir() . self::CACHE_ROOT . '/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir() . self::CACHE_ROOT . '/logs';
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'secret'               => 'host_controllers_test_secret',
            'test'                 => true,
            'csrf_protection'      => true,
            'session'              => ['handler_id' => null, 'storage_factory_id' => 'session.storage.factory.mock_file'],
            'http_method_override' => false,
            'http_client'          => true,
            'translator'           => ['fallbacks' => ['en']],
        ]);
        $container->extension('doctrine', [
            'dbal' => ['url' => 'sqlite:///:memory:'],
            'orm'  => ['auto_mapping' => true],
        ]);
        $container->extension('nowo_translation_yaml_tools', [
            'missing_translation_log' => [
                'enabled' => true,
                'web_ui'  => ['enabled' => true, 'allow_unauthenticated' => true],
            ],
            'overrides' => [
                'enabled' => true,
                'web_ui'  => ['enabled' => true, 'security' => ['allow_unauthenticated' => true]],
            ],
        ]);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('routing.controllers');
    }
}
