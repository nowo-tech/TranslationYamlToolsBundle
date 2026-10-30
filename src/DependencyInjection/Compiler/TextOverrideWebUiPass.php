<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\DependencyInjection\Compiler;

use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Fails the container build when the translation-overrides Web UI is enabled without its
 * requirements (checked after all extensions are merged):
 *
 * - SecurityBundle ({@code security.authorization_checker}) unless
 *   {@code overrides.web_ui.security.allow_unauthenticated} (dev / demo only: refused unless {@code kernel.debug},
 *   see {@see DebugOnlyRequirement});
 * - CSRF protection ({@code security.csrf.token_manager} and the form CSRF extension
 *   {@code form.type_extension.csrf}, i.e. symfony/security-csrf + {@code framework.csrf_protection} without
 *   {@code framework.form.csrf_protection: false}) — see {@see FormCsrfRequirement};
 * - TwigBundle ({@code twig});
 * - the Form component ({@code form.factory}, i.e. symfony/form with {@code framework.form} enabled): the desk
 *   pages are Symfony forms;
 * - NowoUiKitBundle registered in the kernel: the pages are UiKit page scaffolds (see {@see UiKitRequirement}).
 */
final class TextOverrideWebUiPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter('nowo_translation_yaml_tools.overrides.web_ui.enabled')
            || !$container->getParameter('nowo_translation_yaml_tools.overrides.web_ui.enabled')) {
            return;
        }

        $allowUnauthenticated = (bool) $container->getParameter('nowo_translation_yaml_tools.overrides.web_ui.security.allow_unauthenticated');
        if ($allowUnauthenticated) {
            DebugOnlyRequirement::assert($container, 'overrides.web_ui.security.allow_unauthenticated');
        }
        if (!$allowUnauthenticated && !$container->has('security.authorization_checker')) {
            throw new InvalidConfigurationException('overrides.web_ui.enabled requires symfony/security-bundle (security.authorization_checker), or set overrides.web_ui.security.allow_unauthenticated: true (dev/demo only — never in production).');
        }

        FormCsrfRequirement::assert($container, 'overrides.web_ui.enabled');

        if (!$container->has('twig')) {
            throw new InvalidConfigurationException('overrides.web_ui.enabled requires symfony/twig-bundle.');
        }

        if (!$container->has('form.factory')) {
            throw new InvalidConfigurationException('overrides.web_ui.enabled requires the Form component: keep framework.form enabled (symfony/form).');
        }

        UiKitRequirement::assert($container, 'overrides.web_ui.enabled');
    }
}
