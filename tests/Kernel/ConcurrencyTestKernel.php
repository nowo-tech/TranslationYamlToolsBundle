<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Tests\Kernel;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

use function getenv;
use function is_string;
use function md5;
use function sys_get_temp_dir;

/**
 * The text-override fixture app on the real database of TRANSLATION_YAML_TOOLS_TEST_DATABASE_URL (MySQL 8.4,
 * MariaDB 11.4, PostgreSQL 16; the "Databases" CI job), with the overrides desk and the missing-log UI, the bundle
 * writing through its private entity manager (zero config). Used by the two-connection race tests.
 */
final class ConcurrencyTestKernel extends Kernel
{
    use MicroKernelTrait;

    public const URL_ENV = 'TRANSLATION_YAML_TOOLS_TEST_DATABASE_URL';

    public function getProjectDir(): string
    {
        return __DIR__ . '/../Fixtures/app_text_override';
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir() . '/nowo-translation-yaml-tools-concurrency/' . md5(self::url()) . '/cache/' . $this->environment;
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir() . '/nowo-translation-yaml-tools-concurrency/log';
    }

    /**
     * The database URL of the race tests ('' when not set).
     */
    public static function url(): string
    {
        $url = getenv(self::URL_ENV);

        return is_string($url) ? $url : '';
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $dir = $this->getProjectDir() . '/config/packages/';
        $container->import($dir . 'framework.yaml');
        $container->import($dir . 'twig.yaml');
        $container->import($dir . 'nowo_translation_yaml_tools.yaml');
        $container->extension('nowo_translation_yaml_tools', [
            'missing_translation_log' => ['enabled' => true, 'web_ui' => ['enabled' => true, 'security' => ['allow_unauthenticated' => true]]],
        ]);
        $container->extension('doctrine', [
            'dbal' => ['url' => self::url() !== '' ? self::url() : 'sqlite:///:memory:'],
            'orm'  => ['auto_mapping' => true],
        ]);
        $services = $container->services();
        foreach (['Nowo\\TranslationYamlToolsBundle\\TextOverride\\TranslationOverrides', 'Nowo\\TranslationYamlToolsBundle\\Repository\\MissingTranslationLogRepository', 'Nowo\\TranslationYamlToolsBundle\\EventListener\\TranslationYamlToolsConflictListener'] as $id) {
            $services->alias('test.concurrency.' . $id, $id)->public();
        }
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import($this->getProjectDir() . '/config/routes.yaml');
    }
}
