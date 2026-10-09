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
 *   {@code overrides.web_ui.security.allow_unauthenticated} (dev / demo only);
 * - CSRF protection ({@code security.csrf.token_manager}, i.e. symfony/security-csrf +
 *   {@code framework.csrf_protection});
 * - TwigBundle ({@code twig}).
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
        if (!$allowUnauthenticated && !$container->has('security.authorization_checker')) {
            throw new InvalidConfigurationException('overrides.web_ui.enabled requires symfony/security-bundle (security.authorization_checker), or set overrides.web_ui.security.allow_unauthenticated: true (dev/demo only — never in production).');
        }

        if (!$container->has('security.csrf.token_manager')) {
            throw new InvalidConfigurationException('overrides.web_ui.enabled requires CSRF protection: install symfony/security-csrf and enable framework.csrf_protection.');
        }

        if (!$container->has('twig')) {
            throw new InvalidConfigurationException('overrides.web_ui.enabled requires symfony/twig-bundle.');
        }
    }
}
