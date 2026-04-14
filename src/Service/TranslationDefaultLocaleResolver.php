<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Service;

use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

/**
 * Resolves the default locale: bundle override, then Symfony translator.default_locale, then fallback.
 */
class TranslationDefaultLocaleResolver
{
    public function __construct(
        private readonly ParameterBagInterface $parameterBag,
    ) {
    }

    /**
     * @param string|null $bundleConfigured null or empty to use framework default
     */
    public function resolve(?string $bundleConfigured): string
    {
        if ($bundleConfigured !== null && $bundleConfigured !== '') {
            return $bundleConfigured;
        }

        if ($this->parameterBag->has('translator.default_locale')) {
            return (string) $this->parameterBag->get('translator.default_locale');
        }

        if ($this->parameterBag->has('kernel.default_locale')) {
            return (string) $this->parameterBag->get('kernel.default_locale');
        }

        return 'en';
    }
}
