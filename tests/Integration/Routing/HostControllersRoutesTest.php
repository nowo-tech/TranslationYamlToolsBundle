<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Tests\Integration\Routing;

use Nowo\TranslationYamlToolsBundle\Controller\MissingTranslationLogUiController;
use Nowo\TranslationYamlToolsBundle\Tests\Kernel\HostControllersRoutesKernel;
use Nowo\TranslationYamlToolsBundle\TextOverride\Controller\TranslationOverrideController;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Routing\Loader\AttributeServicesLoader;
use Symfony\Component\Routing\RouterInterface;

use function class_exists;
use function is_string;
use function str_starts_with;
use function sys_get_temp_dir;

/**
 * REQ-SF-004 rule 7: a host whose routing is only `routing.controllers` (Symfony >= 7.3 default
 * `config/routes.yaml`) must not get the missing-log or overrides routes without importing the bundle route files.
 */
final class HostControllersRoutesTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(AttributeServicesLoader::class)) {
            self::markTestSkipped('routing.controllers requires symfony/routing >= 7.3.');
        }
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove(sys_get_temp_dir() . HostControllersRoutesKernel::CACHE_ROOT);
    }

    public function testRoutingControllersImportDoesNotRegisterBundleRoutes(): void
    {
        $kernel = new HostControllersRoutesKernel();
        $kernel->boot();

        try {
            $container     = $kernel->getContainer();
            $testContainer = $container->get('test.service_container');
            self::assertInstanceOf(ContainerInterface::class, $testContainer);
            self::assertTrue($testContainer->has(MissingTranslationLogUiController::class), 'The missing-log controller must be registered.');
            self::assertTrue($testContainer->has(TranslationOverrideController::class), 'The overrides controller must be registered.');

            $router = $container->get('router');
            self::assertInstanceOf(RouterInterface::class, $router);

            foreach ($router->getRouteCollection()->all() as $name => $route) {
                self::assertFalse(str_starts_with($name, 'nowo_translation_yaml_tools'), 'Bundle route "' . $name . '" must stay opt-in.');
                $controller = $route->getDefault('_controller');
                self::assertFalse(
                    is_string($controller) && str_starts_with($controller, 'Nowo\\TranslationYamlToolsBundle\\'),
                    'Route "' . $name . '" points to a bundle controller.',
                );
            }
        } finally {
            $kernel->shutdown();
        }
    }
}
