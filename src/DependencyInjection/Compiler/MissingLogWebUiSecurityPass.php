<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\DependencyInjection\Compiler;

use Nowo\TranslationYamlToolsBundle\EventSubscriber\MissingLogUiAccessSubscriber;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

use function is_string;

/**
 * Enforces SecurityBundle for the missing-log Web UI after all extensions are merged.
 *
 * Cannot run in {@see NowoTranslationYamlToolsExtension::load()} — Symfony loads each
 * extension against an isolated container where {@code hasExtension('security')} is false.
 */
final class MissingLogWebUiSecurityPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter('nowo_translation_yaml_tools.missing_translation_log.web_ui.enabled')) {
            return;
        }
        if (!$container->getParameter('nowo_translation_yaml_tools.missing_translation_log.web_ui.enabled')) {
            return;
        }

        $allowUnauthenticated = (bool) $container->getParameter(
            'nowo_translation_yaml_tools.missing_translation_log.web_ui.allow_unauthenticated',
        );
        $hasSecurity = $container->has('security.authorization_checker');

        if (!$hasSecurity && !$allowUnauthenticated) {
            throw new InvalidConfigurationException('missing_translation_log.web_ui.enabled requires symfony/security-bundle (security.authorization_checker), or set missing_translation_log.web_ui.allow_unauthenticated: true (dev/demo only — never in production).');
        }

        $requiredRole = $container->getParameter(
            'nowo_translation_yaml_tools.missing_translation_log.web_ui.required_role',
        );
        if (!$hasSecurity || !is_string($requiredRole) || $requiredRole === '') {
            return;
        }

        if ($container->hasDefinition(MissingLogUiAccessSubscriber::class)) {
            return;
        }

        $container->register(MissingLogUiAccessSubscriber::class, MissingLogUiAccessSubscriber::class)
            ->setArgument('$requiredRole', $requiredRole)
            ->setArgument('$authorizationChecker', new Reference('security.authorization_checker'))
            ->addTag('kernel.event_subscriber');
    }
}
