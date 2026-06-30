<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Service;

use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

use function is_string;

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
            $locale = $this->parameterBag->get('translator.default_locale');
            if (is_string($locale) && $locale !== '') {
                return $locale;
            }
        }

        if ($this->parameterBag->has('kernel.default_locale')) {
            $locale = $this->parameterBag->get('kernel.default_locale');
            if (is_string($locale) && $locale !== '') {
                return $locale;
            }
        }

        return 'en';
    }
}
