<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\DependencyInjection\Compiler;

use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use function sprintf;

/**
 * Shared fail-closed CSRF check of the Web UI compiler passes (REQ-SEC-005).
 *
 * The overrides desk and the missing-log UI validate their mutations through Symfony's form CSRF extension. Action
 * forms declaring their own {@code csrf_protection} / {@code csrf_token_id} options (e.g.
 * {@code MissingTranslationLogActionType}) would validate without a token when {@code form.type_extension.csrf} is
 * missing or {@code framework.form.csrf_protection} is false, so the container refuses to compile instead.
 */
final class FormCsrfRequirement
{
    /**
     * @param string $feature Config path of the enabled Web UI, used in the message (e.g. "overrides.web_ui.enabled")
     *
     * @throws InvalidConfigurationException when the CSRF token manager or the form CSRF extension is unavailable
     */
    public static function assert(ContainerBuilder $container, string $feature): void
    {
        $formCsrfDisabled = $container->hasParameter('form.type_extension.csrf.enabled')
            && $container->getParameter('form.type_extension.csrf.enabled') === false;

        if (
            !$container->has('security.csrf.token_manager')
            || !$container->hasDefinition('form.type_extension.csrf')
            || $formCsrfDisabled
        ) {
            throw new InvalidConfigurationException(sprintf('%s requires CSRF protection: install symfony/security-csrf and enable framework.csrf_protection and framework.form.csrf_protection (or disable the Web UI).', $feature));
        }
    }
}
