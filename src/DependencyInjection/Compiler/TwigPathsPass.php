<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use function dirname;
use function is_dir;
use function rtrim;

/**
 * Registers the bundle Twig namespace when the missing-log Web UI is enabled.
 *
 * REQ-TWIG-001: Application overrides win over vendor. When
 * {@code templates/bundles/NowoTranslationYamlToolsBundle/} exists, it is registered with
 * {@code FilesystemLoader::prependPath()} first; the bundle {@code Resources/views} path is
 * then registered with {@code FilesystemLoader::addPath()}, so the app directory is searched
 * before the bundle copy for {@code @NowoTranslationYamlToolsBundle/...}. Views live under
 * {@code src/Resources/views} (package root), matching {@code FileLocator} for YAML routes.
 */
final class TwigPathsPass implements CompilerPassInterface
{
    private const TWIG_NAMESPACE = 'NowoTranslationYamlToolsBundle';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter('nowo_translation_yaml_tools.missing_translation_log.web_ui.enabled')) {
            return;
        }
        if (!$container->getParameter('nowo_translation_yaml_tools.missing_translation_log.web_ui.enabled')) {
            return;
        }

        $loaderId = $this->getNativeLoaderServiceId($container);
        if ($loaderId === null) {
            return;
        }

        /** @var non-falsy-string $viewsPath */
        $viewsPath = dirname(__DIR__, 2) . '/Resources/views';

        $definition = $container->getDefinition($loaderId);

        if ($container->hasParameter('kernel.project_dir')) {
            $projectDir   = rtrim((string) $container->getParameter('kernel.project_dir'), '/\\');
            $overridePath = $projectDir . '/templates/bundles/NowoTranslationYamlToolsBundle';
            if (is_dir($overridePath)) {
                $definition->addMethodCall('prependPath', [$overridePath, self::TWIG_NAMESPACE]);
            }
        }

        $definition->addMethodCall('addPath', [$viewsPath, self::TWIG_NAMESPACE]);
    }

    private function getNativeLoaderServiceId(ContainerBuilder $container): ?string
    {
        if ($container->hasAlias('twig.loader.native')) {
            $resolved = $this->resolveDefinitionId($container, (string) $container->getAlias('twig.loader.native'));
            if ($resolved !== null) {
                return $resolved;
            }
        }

        if ($container->hasDefinition('twig.loader.native')) {
            return 'twig.loader.native';
        }

        if ($container->hasDefinition('twig.loader.native_filesystem')) {
            return 'twig.loader.native_filesystem';
        }

        return null;
    }

    private function resolveDefinitionId(ContainerBuilder $container, string $id): ?string
    {
        for ($i = 0; $i < 32 && $container->hasAlias($id); ++$i) {
            $id = (string) $container->getAlias($id);
        }

        return $container->hasDefinition($id) ? $id : null;
    }
}
