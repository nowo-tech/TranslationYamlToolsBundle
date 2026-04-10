<?php

declare(strict_types=1);

namespace App\Service;

use Nowo\TranslationYamlToolsBundle\Service\DotKeyTreeAnalyzer;
use Nowo\TranslationYamlToolsBundle\Service\FrameworkTranslationPathsResolver;
use Nowo\TranslationYamlToolsBundle\Service\TranslationDefaultLocaleResolver;
use Nowo\TranslationYamlToolsBundle\Service\TranslationYamlCatalog;
use Nowo\TranslationYamlToolsBundle\Service\TranslationYamlFileHandler;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

/**
 * Builds a structured report of translation paths, locales, files, and missing keys for the demo UI.
 */
final class TranslationInsightsBuilder
{
    public function __construct(
        private readonly ParameterBagInterface $parameters,
        private readonly FrameworkTranslationPathsResolver $pathsResolver,
        private readonly TranslationYamlCatalog $catalog,
        private readonly TranslationYamlFileHandler $fileHandler,
        private readonly DotKeyTreeAnalyzer $dotKeyTreeAnalyzer,
        private readonly TranslationDefaultLocaleResolver $defaultLocaleResolver,
    ) {
    }

    /**
     * @return array{
     *     defaultLocale: string,
     *     enabledLocales: list<string>,
     *     fallbackLocales: list<string>,
     *     directories: list<string>,
     *     directorySourcesHelp: list<string>,
     *     bundleResolvedDefaultLocale: string,
     *     domains: list<array{
     *         name: string,
     *         localesPresent: list<string>,
     *         files: array<string, string|null>,
     *         missingFilesForEnabledLocales: list<string>,
     *         missingKeysVsDefault: array<string, list<string>>
     *     }>
     * }
     */
    public function buildReport(): array
    {
        $defaultLocale = 'en';
        if ($this->parameters->has('translator.default_locale')) {
            $defaultLocale = (string) $this->parameters->get('translator.default_locale');
        } elseif ($this->parameters->has('kernel.default_locale')) {
            $defaultLocale = (string) $this->parameters->get('kernel.default_locale');
        }

        $enabledLocales = [];
        if ($this->parameters->has('kernel.enabled_locales')) {
            /** @var mixed $raw */
            $raw = $this->parameters->get('kernel.enabled_locales');
            $enabledLocales = \is_array($raw) ? array_values(array_map('strval', $raw)) : [(string) $raw];
        } else {
            $enabledLocales = [$defaultLocale];
        }

        $fallbackLocales = [];
        if ($this->parameters->has('translator.fallback_locales')) {
            /** @var mixed $fb */
            $fb = $this->parameters->get('translator.fallback_locales');
            $fallbackLocales = \is_array($fb) ? array_values(array_map('strval', $fb)) : [];
        }

        $bundleOverride = null;
        if ($this->parameters->has('nowo_translation_yaml_tools.default_locale')) {
            /** @var mixed $o */
            $o = $this->parameters->get('nowo_translation_yaml_tools.default_locale');
            $bundleOverride = \is_string($o) && '' !== $o ? $o : null;
        }

        $domainsDetail = [];
        foreach ($this->catalog->listDomains() as $domain) {
            $localesPresent = $this->catalog->listLocalesForDomain($domain);
            $files = [];
            foreach ($localesPresent as $loc) {
                $files[$loc] = $this->catalog->resolveFileForDomainLocale($domain, $loc);
            }

            $missingFiles = [];
            foreach ($enabledLocales as $loc) {
                if (!\in_array($loc, $localesPresent, true)) {
                    $missingFiles[] = $loc;
                }
            }

            $baseFlat = $this->flattenDomainLocale($domain, $defaultLocale);
            $missingKeys = [];
            foreach ($enabledLocales as $loc) {
                if ($loc === $defaultLocale) {
                    continue;
                }
                $path = $this->catalog->resolveFileForDomainLocale($domain, $loc);
                if (null === $path) {
                    continue;
                }
                $targetFlat = $this->flattenFile($path);
                $diff = array_diff_key($baseFlat, $targetFlat);
                if ([] !== $diff) {
                    $missingKeys[$loc] = array_keys($diff);
                }
            }

            $domainsDetail[] = [
                'name' => $domain,
                'localesPresent' => $localesPresent,
                'files' => $files,
                'missingFilesForEnabledLocales' => $missingFiles,
                'missingKeysVsDefault' => $missingKeys,
            ];
        }

        return [
            'defaultLocale' => $defaultLocale,
            'enabledLocales' => $enabledLocales,
            'fallbackLocales' => $fallbackLocales,
            'directories' => $this->pathsResolver->resolveTranslationDirectories(),
            'directorySourcesHelp' => $this->pathsResolver->describeResolutionSources(),
            'bundleResolvedDefaultLocale' => $this->defaultLocaleResolver->resolve($bundleOverride),
            'domains' => $domainsDetail,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function flattenDomainLocale(string $domain, string $locale): array
    {
        $path = $this->catalog->resolveFileForDomainLocale($domain, $locale);
        if (null === $path) {
            return [];
        }

        return $this->flattenFile($path);
    }

    /**
     * @return array<string, mixed>
     */
    private function flattenFile(string $path): array
    {
        $data = $this->fileHandler->loadFile($path);

        return $this->dotKeyTreeAnalyzer->flatten($data);
    }
}
