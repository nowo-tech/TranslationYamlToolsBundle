<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Tests\Kernel;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * Kernel with Doctrine + Twig for translation-override functional tests.
 */
final class TextOverrideTestKernel extends Kernel
{
    use MicroKernelTrait;

    public function getProjectDir(): string
    {
        return __DIR__ . '/../Fixtures/app_text_override';
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->import($this->getProjectDir() . '/config/{packages}/*.yaml');
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import($this->getProjectDir() . '/config/routes.yaml');
    }
}
