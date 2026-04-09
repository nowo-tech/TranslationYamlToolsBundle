<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Service;

use function sprintf;

use const SORT_STRING;

/**
 * Discovers translation domains and locale files under configured translation directories.
 */
class TranslationYamlCatalog
{
    private const DOMAIN_LOCALE_PATTERN = '/^(.+)\.([a-zA-Z0-9_-]+)\.(yaml|yml)$/';

    public function __construct(
        private readonly FrameworkTranslationPathsResolver $pathsResolver,
    ) {
    }

    /**
     * @return list<string> Unique domain names that have at least one YAML file
     */
    public function listDomains(): array
    {
        $domains = [];
        foreach ($this->pathsResolver->resolveTranslationDirectories() as $dir) {
            $files = is_dir($dir) ? $this->scanDomainFiles($dir) : [];
            foreach ($files as $info) {
                $domains[$info['domain']] = true;
            }
        }

        $keys = array_keys($domains);
        sort($keys, SORT_STRING);

        return $keys;
    }

    /**
     * Locales available for a domain (from filenames).
     *
     * @return list<string>
     */
    public function listLocalesForDomain(string $domain): array
    {
        $locales = [];
        foreach ($this->pathsResolver->resolveTranslationDirectories() as $dir) {
            foreach ($this->scanDomainFiles($dir) as $info) {
                if ($info['domain'] === $domain) {
                    $locales[$info['locale']] = true;
                }
            }
        }

        $keys = array_keys($locales);
        sort($keys, SORT_STRING);

        return $keys;
    }

    /**
     * Resolves the first matching YAML file for domain + locale (searches dirs in order).
     */
    public function resolveFileForDomainLocale(string $domain, string $locale): ?string
    {
        foreach (['yaml', 'yml'] as $ext) {
            $name = sprintf('%s.%s.%s', $domain, $locale, $ext);
            foreach ($this->pathsResolver->resolveTranslationDirectories() as $dir) {
                $path = $dir . '/' . $name;
                if (is_file($path)) {
                    return $path;
                }
            }
        }

        return null;
    }

    /**
     * @return list<array{domain: string, locale: string, path: string}>
     */
    private function scanDomainFiles(string $directory): array
    {
        $out   = [];
        $paths = array_merge(
            glob($directory . '/*.yaml') ?: [],
            glob($directory . '/*.yml') ?: [],
        );
        foreach ($paths as $path) {
            $base = basename((string) $path);
            if (preg_match(self::DOMAIN_LOCALE_PATTERN, $base, $m)) {
                $out[] = [
                    'domain' => $m[1],
                    'locale' => $m[2],
                    'path'   => (string) $path,
                ];
            }
        }

        return $out;
    }
}
