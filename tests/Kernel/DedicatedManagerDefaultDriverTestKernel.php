<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Tests\Kernel;

use Doctrine\ORM\Mapping\Driver\AttributeDriver;
use Nowo\TranslationYamlToolsBundle\Repository\MissingTranslationLogRepository;
use Nowo\TranslationYamlToolsBundle\Tests\Fixtures\Host\HostDefaultManagerExtension;
use Nowo\TranslationYamlToolsBundle\TextOverride\Repository\TranslationOverrideRepository;
use Nowo\TranslationYamlToolsBundle\TextOverride\TranslationOverrideEditor;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;

use function sprintf;
use function sys_get_temp_dir;

/**
 * DoctrineBundle 3.4 regression host: the bundle entities on a dedicated "texts" manager (`doctrine.entity_manager`,
 * own in-memory SQLite database), the host's default manager on its own database WITHOUT `mappings` and
 * `auto_mapping`, so DoctrineBundle 3.4 `auto_discover_entities` gives its chain a default attribute driver that claims
 * every `#[ORM\Entity]` class.
 *
 * - `$explicitDefaultDriver`: a host compiler pass gives the default chain an explicit catch-all
 *   `setDefaultDriver(AttributeDriver)`, which reproduces the same on every DoctrineBundle version;
 * - `$hostManagerFirst`: another bundle prepends configuration for the default manager after this bundle, so the
 *   default manager comes first in the registry (otherwise the bundle's prepended manager is first).
 */
final class DedicatedManagerDefaultDriverTestKernel extends Kernel
{
    use MicroKernelTrait;

    public function __construct(
        private readonly bool $explicitDefaultDriver,
        private readonly bool $hostManagerFirst,
    ) {
        parent::__construct('test', false);
    }

    public function getProjectDir(): string
    {
        return __DIR__ . '/../Fixtures/app_text_override';
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir() . '/nowo-translation-yaml-tools-default-driver/' . $this->variant() . '/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir() . '/nowo-translation-yaml-tools-default-driver/' . $this->variant() . '/log';
    }

    protected function build(ContainerBuilder $container): void
    {
        if ($this->hostManagerFirst) {
            $container->registerExtension(new HostDefaultManagerExtension());
        }
        if ($this->explicitDefaultDriver) {
            $container->addCompilerPass(new class implements CompilerPassInterface {
                public function process(ContainerBuilder $container): void
                {
                    // An attribute driver answers "not transient" for any class carrying #[ORM\Entity], whatever its paths.
                    $container->getDefinition('doctrine.orm.default_metadata_driver')
                        ->addMethodCall('setDefaultDriver', [new Definition(AttributeDriver::class, [[__DIR__ . '/../Fixtures/Host'], true])]);
                }
            });
        }
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $dir = $this->getProjectDir() . '/config/packages/';
        $container->import($dir . 'framework.yaml');
        $container->import($dir . 'twig.yaml');
        $container->extension('nowo_translation_yaml_tools', [
            'doctrine'                => ['entity_manager' => 'texts'],
            'overrides'               => ['enabled' => true, 'editable' => ['messages' => ['site.']]],
            'missing_translation_log' => ['enabled' => true],
        ]);
        $container->extension('doctrine', [
            'dbal' => ['connections' => ['default' => ['url' => 'sqlite:///:memory:'], 'texts' => ['url' => 'sqlite:///:memory:']]],
            'orm'  => ['default_entity_manager' => 'default', 'entity_managers' => ['default' => ['connection' => 'default'], 'texts' => ['connection' => 'texts']]],
        ]);

        $services = $container->services();
        $services->set('logger', NullLogger::class);
        $services->alias('test.dd.editor', TranslationOverrideEditor::class)->public();
        $services->alias('test.dd.override_repository', TranslationOverrideRepository::class)->public();
        $services->alias('test.dd.log_repository', MissingTranslationLogRepository::class)->public();
    }

    private function variant(): string
    {
        return sprintf('%d%d', (int) $this->explicitDefaultDriver, (int) $this->hostManagerFirst);
    }
}
