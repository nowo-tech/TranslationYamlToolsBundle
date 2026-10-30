<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\DependencyInjection\Compiler;

use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use function is_array;
use function sprintf;

/**
 * Shared check of the Web UI compiler passes (REQ-UI-003): the pages are UiKit page scaffolds, so the container
 * refuses to compile when a Web UI is enabled without NowoUiKitBundle registered in the kernel (instead of a Twig
 * "unknown function nowo_ui_class" error on the first request).
 */
final class UiKitRequirement
{
    /**
     * @param string $feature Config path of the enabled Web UI, used in the message (e.g. "overrides.web_ui.enabled")
     *
     * @throws InvalidConfigurationException when NowoUiKitBundle is not registered
     */
    public static function assert(ContainerBuilder $container, string $feature): void
    {
        $bundles = $container->hasParameter('kernel.bundles') ? $container->getParameter('kernel.bundles') : [];

        if (!is_array($bundles) || !isset($bundles['NowoUiKitBundle'])) {
            throw new InvalidConfigurationException(sprintf('%s requires NowoUiKitBundle (nowo-tech/ui-kit-bundle): register Nowo\UiKitBundle\NowoUiKitBundle in config/bundles.php (the Web UI pages are UiKit page scaffolds, REQ-UI-003), or disable the Web UI.', $feature));
        }
    }
}
