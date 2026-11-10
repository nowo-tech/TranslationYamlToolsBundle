<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Tests\Kernel;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

use function sys_get_temp_dir;

/**
 * The text-override fixture app as a DDD-style host: the bundle entities on a dedicated "texts" manager
 * (doctrine.entity_manager), the host's own entities on "default".
 */
final class WorkerTextOverrideTestKernel extends Kernel
{
    use MicroKernelTrait;

    public function getProjectDir(): string
    {
        return __DIR__ . '/../Fixtures/app_text_override';
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir() . '/nowo-translation-yaml-tools-worker/cache/' . $this->environment;
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir() . '/nowo-translation-yaml-tools-worker/log';
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $dir = $this->getProjectDir() . '/config/packages/';
        $container->import($dir . 'framework.yaml');
        $container->import($dir . 'twig.yaml');
        $container->import($dir . 'nowo_translation_yaml_tools.yaml');
        $container->extension('nowo_translation_yaml_tools', ['doctrine' => ['entity_manager' => 'texts']]);
        $container->extension('doctrine', [
            'dbal' => [
                'default_connection' => 'default',
                'connections'        => [
                    'default' => ['url' => 'sqlite:///:memory:'],
                    'texts'   => ['url' => 'sqlite:///:memory:'],
                ],
            ],
            'orm' => [
                'default_entity_manager' => 'default',
                'entity_managers'        => [
                    'default' => ['connection' => 'default'],
                    'texts'   => ['connection' => 'texts'],
                ],
            ],
        ]);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import($this->getProjectDir() . '/config/routes.yaml');
    }
}
