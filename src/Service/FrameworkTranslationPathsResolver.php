<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Service;

use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Yaml\Yaml;
use Throwable;

use function is_array;
use function is_string;

use const GLOB_ONLYDIR;

/**
 * Resolves translation resource directories from Symfony parameters and framework YAML config.
 */
class FrameworkTranslationPathsResolver
{
    public function __construct(
        private readonly KernelInterface $kernel,
        private readonly ParameterBagInterface $parameterBag,
    ) {
    }

    /**
     * Returns absolute directory paths where YAML translation files are loaded from.
     *
     * @return list<string>
     */
    public function resolveTranslationDirectories(): array
    {
        $paths = [];

        if ($this->parameterBag->has('translator.default_path')) {
            /** @var string $defaultPath */
            $defaultPath = $this->parameterBag->get('translator.default_path');
            $paths[]     = $defaultPath;
        } else {
            $paths[] = $this->kernel->getProjectDir() . '/translations';
        }

        $paths = array_merge($paths, $this->pathsFromFrameworkYaml());

        $resolved = [];
        foreach ($paths as $path) {
            /** @var string $resolvedPath */
            $resolvedPath = $this->parameterBag->resolveValue($path);
            if (is_dir($resolvedPath)) {
                $resolved[] = $resolvedPath;
            }
        }

        return array_values(array_unique($resolved));
    }

    /**
     * Human-readable summary of where paths were discovered (parameters vs config files).
     *
     * @return list<string>
     */
    public function describeResolutionSources(): array
    {
        $lines = [];
        if ($this->parameterBag->has('translator.default_path')) {
            $lines[] = 'Parameter translator.default_path is set (FrameworkBundle).';
        } else {
            $lines[] = 'translator.default_path not set; using %kernel.project_dir%/translations.';
        }

        $lines[] = 'Merged paths from config/packages/**/translation*.yaml (framework.translator.paths and default_path).';

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function pathsFromFrameworkYaml(): array
    {
        $packagesDir = $this->kernel->getProjectDir() . '/config/packages';
        if (!is_dir($packagesDir)) {
            return [];
        }

        $extra = [];
        foreach ($this->discoverTranslationConfigFiles($packagesDir) as $configPath) {
            try {
                /** @var array<string, mixed> $parsed */
                $parsed = Yaml::parseFile($configPath) ?? [];
            } catch (Throwable) {
                continue;
            }

            $framework = $parsed['framework'] ?? null;
            if (!is_array($framework)) {
                continue;
            }

            $translator = $framework['translator'] ?? null;
            if (!is_array($translator)) {
                continue;
            }

            if (isset($translator['default_path']) && is_string($translator['default_path'])) {
                $extra[] = $translator['default_path'];
            }

            $paths = $translator['paths'] ?? null;
            if (is_array($paths)) {
                foreach ($paths as $p) {
                    if (is_string($p) && $p !== '') {
                        $extra[] = $p;
                    }
                }
            }
        }

        return $extra;
    }

    /**
     * @return list<string>
     */
    private function discoverTranslationConfigFiles(string $packagesDir): array
    {
        $files = [];
        foreach (['translation.yaml', 'translation.yml'] as $name) {
            $p = $packagesDir . '/' . $name;
            if (is_file($p)) {
                $files[] = $p;
            }
        }

        foreach (glob($packagesDir . '/*', GLOB_ONLYDIR) ?: [] as $sub) {
            foreach (['translation.yaml', 'translation.yml'] as $name) {
                $p = $sub . '/' . $name;
                if (is_file($p)) {
                    $files[] = $p;
                }
            }
        }

        return array_values(array_unique($files));
    }
}
