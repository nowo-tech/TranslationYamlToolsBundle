<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Tests\Kernel;

use Nowo\TranslationYamlToolsBundle\Tests\Fixtures\Ddd\Domain\Page;
use Nowo\TranslationYamlToolsBundle\TextOverride\TranslationOverrideEditor;
use Nowo\TranslationYamlToolsBundle\TextOverride\TranslationOverrideProviderInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;

use function sys_get_temp_dir;

/**
 * DDD / hexagonal host (REQ-ARCH-001): the host domain is plain PHP mapped in XML on its own default manager
 * ({@see Page}, no auto_mapping, no bundle base class or
 * trait, the text referenced by translation key only); the bundle entities live on a dedicated "translations" manager
 * (`doctrine.entity_manager`) on the same connection. The host talks to the bundle through
 * {@see TranslationOverrideProviderInterface} (read port) and {@see TranslationOverrideEditor} (write service).
 */
final class DddHostTestKernel extends Kernel
{
    use MicroKernelTrait;

    public function getProjectDir(): string
    {
        return __DIR__ . '/../Fixtures/app_text_override';
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir() . '/nowo-translation-yaml-tools-ddd/cache/' . $this->environment;
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir() . '/nowo-translation-yaml-tools-ddd/log';
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $dir = $this->getProjectDir() . '/config/packages/';
        $container->import($dir . 'framework.yaml');
        $container->import($dir . 'twig.yaml');
        $container->extension('nowo_translation_yaml_tools', [
            'doctrine'                => ['entity_manager' => 'translations'],
            'overrides'               => ['enabled' => true, 'editable' => ['messages' => ['site.']]],
            'missing_translation_log' => ['enabled' => true],
        ]);
        $container->extension('doctrine', [
            'dbal' => ['url' => 'sqlite:///:memory:'],
            'orm'  => [
                'default_entity_manager' => 'default',
                'entity_managers'        => [
                    'default' => [
                        'connection' => 'default',
                        'mappings'   => [
                            'Domain' => [
                                'type'      => 'xml',
                                'dir'       => __DIR__ . '/../Fixtures/Ddd/mapping',
                                'prefix'    => 'Nowo\\TranslationYamlToolsBundle\\Tests\\Fixtures\\Ddd\\Domain',
                                'is_bundle' => false,
                            ],
                        ],
                    ],
                    'translations' => ['connection' => 'default'],
                ],
            ],
        ]);

        $services = $container->services();
        $services->set('logger', NullLogger::class);
        $services->alias('test.ddd.texts', TranslationOverrideProviderInterface::class)->public();
        $services->alias('test.ddd.editor', TranslationOverrideEditor::class)->public();
        $services->alias('test.ddd.translator', 'translator')->public();
    }
}
