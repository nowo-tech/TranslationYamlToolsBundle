<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\DependencyInjection\Compiler;

use Nowo\TranslationYamlToolsBundle\Controller\MissingTranslationLogUiController;
use Nowo\TranslationYamlToolsBundle\EventSubscriber\MissingLogUiAccessSubscriber;
use Nowo\TranslationYamlToolsBundle\Security\MissingLogUiAccessCheckerInterface;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

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
            'nowo_translation_yaml_tools.missing_translation_log.web_ui.security.allow_unauthenticated',
        );
        $hasSecurity = $container->has('security.authorization_checker');

        if (!$hasSecurity && !$allowUnauthenticated) {
            throw new InvalidConfigurationException('missing_translation_log.web_ui.enabled requires symfony/security-bundle (security.authorization_checker), or set missing_translation_log.web_ui.security.allow_unauthenticated: true (dev/demo only — never in production).');
        }

        if ($allowUnauthenticated) {
            return;
        }

        /** @var list<string> $accessRoles */
        $accessRoles   = $container->getParameter('nowo_translation_yaml_tools.missing_translation_log.web_ui.security.access_roles');
        $customChecker = (bool) $container->getParameter('nowo_translation_yaml_tools.missing_translation_log.web_ui.security.custom_access_checker');
        // Empty access_roles with the default checker = no bundle-level enforcement (firewall only).
        if ($accessRoles === [] && !$customChecker) {
            return;
        }

        if (!$container->has(MissingLogUiAccessCheckerInterface::class)) {
            return;
        }

        if ($container->hasDefinition(MissingTranslationLogUiController::class)) {
            $container->getDefinition(MissingTranslationLogUiController::class)
                ->setArgument('$accessChecker', new Reference(MissingLogUiAccessCheckerInterface::class));
        }

        if ($container->hasDefinition(MissingLogUiAccessSubscriber::class)) {
            return;
        }

        $definition = $container->register(MissingLogUiAccessSubscriber::class, MissingLogUiAccessSubscriber::class)
            ->setArgument('$accessChecker', new Reference(MissingLogUiAccessCheckerInterface::class))
            ->addTag('kernel.event_subscriber');

        if ($container->has('security.token_storage')) {
            $definition->setArgument('$tokenStorage', new Reference('security.token_storage'));
        }
    }
}
