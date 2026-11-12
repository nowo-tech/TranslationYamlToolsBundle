<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Tests\Kernel;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\TranslationYamlToolsBundle\Doctrine\TranslationYamlToolsEntityManager;
use Nowo\TranslationYamlToolsBundle\EventSubscriber\EntityManagerBoundarySubscriber;
use Nowo\TranslationYamlToolsBundle\Repository\MissingTranslationLogRepository;
use Nowo\TranslationYamlToolsBundle\Tests\Fixtures\Host\HostNote;
use Nowo\TranslationYamlToolsBundle\Tests\Fixtures\Host\TenantFilter;
use Nowo\TranslationYamlToolsBundle\TextOverride\Repository\TranslationOverrideRepository;
use Nowo\TranslationYamlToolsBundle\TextOverride\TranslationOverrideEditor;
use Nowo\TranslationYamlToolsBundle\TextOverride\TranslationOverrides;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Symfony\Contracts\Translation\TranslatorInterface;

use function assert;
use function sys_get_temp_dir;

/**
 * Standard Symfony host (REQ-ARCH-001 rule 6): one default entity manager on one in-memory SQLite connection maps the
 * host's own attribute entity ({@see HostNote}) and, through the bundle's prepended mapping, the bundle entities. Text
 * overrides and the missing-translation log are on, with a host SQL filter.
 *
 * Variants:
 * - `private` (zero config, 1.18.0 default): the bundle writes through its private entity manager;
 * - `legacy`: `doctrine.private_entity_manager: false` (deprecated): the pre-1.18 behaviour on the default manager;
 * - `dedicated`: `doctrine.entity_manager: texts`, the bundle entities on a host-declared "texts" manager.
 *
 * Pages: `/greet/{locale}` renders the greeting (and asks for a key no catalogue ships, so the missing-translation log
 * records one hit per request and locale); `/edit/{locale}?text=…` first stages a host note on the application's
 * manager (never flushed by the host), then saves the greeting override through the editor and renders it.
 */
final class PrivateEntityManagerTestKernel extends Kernel
{
    use MicroKernelTrait;

    public const KEY = 'site.greeting';

    public function __construct(string $environment = 'test', bool $debug = false, private readonly string $variant = 'private')
    {
        parent::__construct($environment, $debug);
    }

    public function getProjectDir(): string
    {
        return __DIR__ . '/../Fixtures/app_text_override';
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir() . '/nowo-translation-yaml-tools-private-em/' . $this->variant . '/cache/' . $this->environment;
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir() . '/nowo-translation-yaml-tools-private-em/' . $this->variant . '/log';
    }

    public function greet(string $locale): Response
    {
        $translator = $this->getContainer()->get('test.pem.translator');
        assert($translator instanceof TranslatorInterface);

        // A key no catalogue defines: recorded by the missing-translation log, persisted at kernel.terminate.
        $translator->trans('site.not_shipped', [], 'messages', $locale);

        return new Response($translator->trans(self::KEY, ['%name%' => $locale], 'messages', $locale));
    }

    public function edit(string $locale, Request $request): Response
    {
        $host = $this->getContainer()->get('test.pem.host');
        assert($host instanceof EntityManagerInterface);
        $host->persist(new HostNote('request ' . $locale));

        $editor = $this->getContainer()->get('test.pem.editor');
        assert($editor instanceof TranslationOverrideEditor);
        $editor->save('messages', self::KEY, [$locale => $request->query->getString('text')], updatedBy: 'user-' . $locale);

        return $this->greet($locale);
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $dir = $this->getProjectDir() . '/config/packages/';
        $container->import($dir . 'framework.yaml');
        $container->import($dir . 'twig.yaml');

        $tools = [
            'overrides'               => ['enabled' => true, 'editable' => ['messages' => ['site.']]],
            'missing_translation_log' => ['enabled' => true],
        ];
        $host = [
            'mappings' => [
                'Host' => [
                    'type'      => 'attribute',
                    'dir'       => __DIR__ . '/../Fixtures/Host',
                    'prefix'    => 'Nowo\\TranslationYamlToolsBundle\\Tests\\Fixtures\\Host',
                    'is_bundle' => false,
                ],
            ],
            'filters' => ['tenant' => ['class' => TenantFilter::class, 'enabled' => false]],
        ];
        if ($this->variant === 'dedicated') {
            $tools['doctrine'] = ['entity_manager' => 'texts'];
            $container->extension('doctrine', [
                'dbal' => ['connections' => ['default' => ['url' => 'sqlite:///:memory:'], 'texts' => ['url' => 'sqlite:///:memory:']]],
                'orm'  => ['entity_managers' => ['default' => $host + ['connection' => 'default'], 'texts' => ['connection' => 'texts']]],
            ]);
        } else {
            if ($this->variant === 'legacy') {
                $tools['doctrine'] = ['private_entity_manager' => false];
            }
            $container->extension('doctrine', ['dbal' => ['url' => 'sqlite:///:memory:'], 'orm' => $host + ['auto_mapping' => true]]);
        }
        $container->extension('nowo_translation_yaml_tools', $tools);

        $services = $container->services();
        $services->set('logger', NullLogger::class);
        $services->alias('test.pem.translator', 'translator')->public();
        $services->alias('test.pem.host', 'doctrine.orm.default_entity_manager')->public();
        $services->alias('test.pem.editor', TranslationOverrideEditor::class)->public();
        $services->alias('test.pem.overrides', TranslationOverrides::class)->public();
        $services->alias('test.pem.override_repository', TranslationOverrideRepository::class)->public();
        $services->alias('test.pem.log_repository', MissingTranslationLogRepository::class)->public();
        $services->alias('test.pem.boundaries', EntityManagerBoundarySubscriber::class)->public();
        if ($this->variant === 'private') {
            $services->alias('test.pem.private', TranslationYamlToolsEntityManager::class)->public();
        }
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->add('test_greet', '/greet/{locale}')->controller('kernel::greet');
        $routes->add('test_edit', '/edit/{locale}')->controller('kernel::edit');
    }
}
