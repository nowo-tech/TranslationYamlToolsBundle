<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\DependencyInjection\Compiler;

use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use function sprintf;

/**
 * Shared check of the Web UI compiler passes (REQ-SEC-004 L2): `web_ui.security.allow_unauthenticated: true` is a
 * dev / demo switch, so the container refuses to compile it when `kernel.debug` is false (or unknown) instead of
 * exposing the Web UI without login in production.
 */
final class DebugOnlyRequirement
{
    /**
     * @param string $option Config path of the switch, used in the message (e.g. "overrides.web_ui.security.allow_unauthenticated")
     *
     * @throws InvalidConfigurationException when kernel.debug is not true
     */
    public static function assert(ContainerBuilder $container, string $option): void
    {
        if ($container->hasParameter('kernel.debug') && $container->getParameter('kernel.debug') === true) {
            return;
        }

        throw new InvalidConfigurationException(sprintf('%s: true is only allowed when kernel.debug is true (dev / demo); the Web UI would be reachable without login. Set it to false and protect the UI with security.access_roles.', $option));
    }
}
