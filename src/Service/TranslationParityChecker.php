<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Service;

use Nowo\TranslationYamlToolsBundle\Testing\TranslationParityAssertions;
use Throwable;

use function array_key_exists;
use function array_keys;
use function basename;
use function fnmatch;
use function glob;
use function is_dir;
use function is_file;
use function is_string;
use function preg_match;
use function rtrim;
use function sort;
use function sprintf;
use function str_contains;
use function trim;

use const FNM_NOESCAPE;
use const SORT_STRING;

/**
 * Strict key parity between a source locale and every other locale of a translation directory
 * (missing keys, extra keys, empty values) plus detection of values copied untranslated from the source.
 *
 * Pure service (no container): used by {@see TranslationYamlAuditor} for `audit --strict` and by
 * {@see TranslationParityAssertions} in host test suites.
 *
 * Allowlists are maps `locale => list of "domain:key" patterns` (`fnmatch()` syntax); the locale `*` applies to every locale.
 */
final class TranslationParityChecker
{
    public const TYPE_SOURCE_MISSING = 'source_missing';
    public const TYPE_YAML_ERROR     = 'yaml_error';
    public const TYPE_MISSING        = 'missing';
    public const TYPE_EXTRA          = 'extra';
    public const TYPE_EMPTY          = 'empty';
    public const TYPE_UNTRANSLATED   = 'untranslated';

    private const FILE_PATTERN = '/^(.+)\.([a-zA-Z0-9_-]+)\.(yaml|yml)$/';

    private readonly TranslationYamlFileHandler $fileHandler;
    private readonly DotKeyTreeAnalyzer $analyzer;

    public function __construct(?TranslationYamlFileHandler $fileHandler = null, ?DotKeyTreeAnalyzer $analyzer = null)
    {
        $this->fileHandler = $fileHandler ?? new TranslationYamlFileHandler();
        $this->analyzer    = $analyzer ?? new DotKeyTreeAnalyzer();
    }

    /**
     * Source keys absent from the target (in source order).
     *
     * @param array<string, mixed> $sourceFlat
     * @param array<string, mixed> $targetFlat
     *
     * @return list<string>
     */
    public function missingKeys(array $sourceFlat, array $targetFlat): array
    {
        $out = [];
        foreach (array_keys($sourceFlat) as $key) {
            if (!array_key_exists($key, $targetFlat)) {
                $out[] = (string) $key;
            }
        }

        return $out;
    }

    /**
     * Target keys absent from the source (in target order).
     *
     * @param array<string, mixed> $sourceFlat
     * @param array<string, mixed> $targetFlat
     *
     * @return list<string>
     */
    public function extraKeys(array $sourceFlat, array $targetFlat): array
    {
        return $this->missingKeys($targetFlat, $sourceFlat);
    }

    /**
     * Keys whose value is null, a blank string or an empty map.
     *
     * @param array<string, mixed> $flat
     *
     * @return list<string>
     */
    public function emptyKeys(array $flat): array
    {
        $out = [];
        foreach ($flat as $key => $value) {
            if ($value === null || $value === [] || (is_string($value) && trim($value) === '')) {
                $out[] = (string) $key;
            }
        }

        return $out;
    }

    /**
     * Keys whose target value is identical to the source value.
     *
     * With `$multiWordOnly`, only source values containing a space (after trim) and not starting with `%` or `{`
     * (placeholders, ICU) are considered: one-word labels are often legitimately identical.
     *
     * @param array<string, mixed> $sourceFlat
     * @param array<string, mixed> $targetFlat
     *
     * @return list<string>
     */
    public function untranslatedKeys(array $sourceFlat, array $targetFlat, bool $multiWordOnly = true): array
    {
        $out = [];
        foreach ($sourceFlat as $key => $value) {
            if (!is_string($value) || trim($value) === '') {
                continue;
            }
            if ($multiWordOnly && (!str_contains(trim($value), ' ') || preg_match('/^[%{]/', $value) === 1)) {
                continue;
            }
            if (($targetFlat[$key] ?? null) === $value) {
                $out[] = (string) $key;
            }
        }

        return $out;
    }

    /**
     * Domains and locales found in a directory (`domain.locale.yaml|yml`), both sorted.
     *
     * @return array{domains: list<string>, locales: list<string>}
     */
    public function discover(string $directory): array
    {
        $domains = [];
        $locales = [];
        foreach ($this->scan($directory) as $info) {
            $domains[$info['domain']] = true;
            $locales[$info['locale']] = true;
        }
        $d = array_keys($domains);
        $l = array_keys($locales);
        sort($d, SORT_STRING);
        sort($l, SORT_STRING);

        return ['domains' => $d, 'locales' => $l];
    }

    /**
     * Strict parity check of a translation directory.
     *
     * A locale listed in `$locales` without a file for a domain reports every source key as missing.
     *
     * @param list<string>|null $domains null = every domain found in the directory
     * @param list<string>|null $locales null = every locale found in the directory
     * @param array<string, list<string>> $allowedDifferences locale|"*" => "domain:key" patterns allowed to be missing / extra / empty
     *
     * @return list<array{type: string, domain: string, locale: string, key: ?string, detail: ?string}>
     */
    public function checkParity(
        string $directory,
        string $sourceLocale,
        ?array $domains = null,
        ?array $locales = null,
        array $allowedDifferences = [],
        bool $checkEmpty = true,
    ): array {
        $directory  = rtrim($directory, '/');
        $discovered = $this->discover($directory);
        $violations = [];

        foreach ($domains ?? $discovered['domains'] as $domain) {
            $source = $this->loadFlat($directory, $domain, $sourceLocale);
            if ($source['error'] !== null || $source['flat'] === []) {
                $violations[] = $this->sourceViolation($domain, $sourceLocale, $source['error']);
                continue;
            }

            if ($checkEmpty) {
                foreach ($this->emptyKeys($source['flat']) as $key) {
                    if (!$this->isAllowed($allowedDifferences, $sourceLocale, $domain, $key)) {
                        $violations[] = $this->violation(self::TYPE_EMPTY, $domain, $sourceLocale, $key);
                    }
                }
            }

            foreach ($locales ?? $discovered['locales'] as $locale) {
                if ($locale === $sourceLocale) {
                    continue;
                }
                $target = $this->loadFlat($directory, $domain, $locale);
                if ($target['error'] !== null) {
                    $violations[] = $this->violation(self::TYPE_YAML_ERROR, $domain, $locale, null, $target['error']);
                    continue;
                }
                if ($locales === null && !$target['exists']) {
                    continue;
                }

                $checks = [
                    self::TYPE_MISSING => $this->missingKeys($source['flat'], $target['flat']),
                    self::TYPE_EXTRA   => $this->extraKeys($source['flat'], $target['flat']),
                    self::TYPE_EMPTY   => $checkEmpty ? $this->emptyKeys($target['flat']) : [],
                ];
                foreach ($checks as $type => $keys) {
                    foreach ($keys as $key) {
                        if (!$this->isAllowed($allowedDifferences, $locale, $domain, $key)) {
                            $violations[] = $this->violation($type, $domain, $locale, $key);
                        }
                    }
                }
            }
        }

        return $violations;
    }

    /**
     * Values copied from the source locale and never translated.
     *
     * @param list<string>|null $domains null = every domain found in the directory
     * @param list<string>|null $locales null = every locale found in the directory
     * @param array<string, list<string>> $allowedSameAsSource locale|"*" => "domain:key" patterns allowed to equal the source
     *
     * @return list<array{type: string, domain: string, locale: string, key: ?string, detail: ?string}>
     */
    public function checkUntranslated(
        string $directory,
        string $sourceLocale,
        ?array $domains = null,
        ?array $locales = null,
        array $allowedSameAsSource = [],
        bool $multiWordOnly = true,
    ): array {
        $directory  = rtrim($directory, '/');
        $discovered = $this->discover($directory);
        $violations = [];

        foreach ($domains ?? $discovered['domains'] as $domain) {
            $source = $this->loadFlat($directory, $domain, $sourceLocale);
            if ($source['error'] !== null || $source['flat'] === []) {
                $violations[] = $this->sourceViolation($domain, $sourceLocale, $source['error']);
                continue;
            }

            foreach ($locales ?? $discovered['locales'] as $locale) {
                if ($locale === $sourceLocale) {
                    continue;
                }
                $target = $this->loadFlat($directory, $domain, $locale);
                if ($target['error'] !== null) {
                    $violations[] = $this->violation(self::TYPE_YAML_ERROR, $domain, $locale, null, $target['error']);
                    continue;
                }
                foreach ($this->untranslatedKeys($source['flat'], $target['flat'], $multiWordOnly) as $key) {
                    if (!$this->isAllowed($allowedSameAsSource, $locale, $domain, $key)) {
                        $violations[] = $this->violation(self::TYPE_UNTRANSLATED, $domain, $locale, $key);
                    }
                }
            }
        }

        return $violations;
    }

    /**
     * One line per violation, e.g. `es messages: missing key "a.b"`.
     *
     * @param list<array{type: string, domain: string, locale: string, key: ?string, detail: ?string}> $violations
     *
     * @return list<string>
     */
    public static function format(array $violations): array
    {
        $out = [];
        foreach ($violations as $v) {
            $out[] = $v['key'] !== null
                ? sprintf('%s %s: %s key "%s"', $v['locale'], $v['domain'], $v['type'], $v['key'])
                : sprintf('%s %s: %s (%s)', $v['locale'], $v['domain'], $v['type'], (string) $v['detail']);
        }

        return $out;
    }

    /**
     * @param array<string, list<string>> $allowlist
     */
    public function isAllowed(array $allowlist, string $locale, string $domain, string $key): bool
    {
        $ref = $domain . ':' . $key;
        foreach ([$locale, '*'] as $scope) {
            foreach ($allowlist[$scope] ?? [] as $pattern) {
                if ($pattern === $ref || fnmatch($pattern, $ref, FNM_NOESCAPE)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return array{exists: bool, flat: array<string, mixed>, error: ?string}
     */
    private function loadFlat(string $directory, string $domain, string $locale): array
    {
        foreach (['yaml', 'yml'] as $ext) {
            $path = sprintf('%s/%s.%s.%s', $directory, $domain, $locale, $ext);
            if (!is_file($path)) {
                continue;
            }
            try {
                return ['exists' => true, 'flat' => $this->analyzer->flatten($this->fileHandler->loadFile($path)), 'error' => null];
            } catch (Throwable $e) {
                return ['exists' => true, 'flat' => [], 'error' => $e->getMessage()];
            }
        }

        return ['exists' => false, 'flat' => [], 'error' => null];
    }

    /**
     * @return list<array{domain: string, locale: string}>
     */
    private function scan(string $directory): array
    {
        if (!is_dir($directory)) {
            return [];
        }
        $out = [];
        foreach ([...(glob($directory . '/*.yaml') ?: []), ...(glob($directory . '/*.yml') ?: [])] as $path) {
            if (preg_match(self::FILE_PATTERN, basename($path), $m) === 1) {
                $out[] = ['domain' => $m[1], 'locale' => $m[2]];
            }
        }

        return $out;
    }

    /**
     * @return array{type: string, domain: string, locale: string, key: ?string, detail: ?string}
     */
    private function sourceViolation(string $domain, string $sourceLocale, ?string $error): array
    {
        return $error !== null
            ? $this->violation(self::TYPE_YAML_ERROR, $domain, $sourceLocale, null, $error)
            : $this->violation(self::TYPE_SOURCE_MISSING, $domain, $sourceLocale, null, sprintf('%s.%s.yaml is missing or empty.', $domain, $sourceLocale));
    }

    /**
     * @return array{type: string, domain: string, locale: string, key: ?string, detail: ?string}
     */
    private function violation(string $type, string $domain, string $locale, ?string $key, ?string $detail = null): array
    {
        return ['type' => $type, 'domain' => $domain, 'locale' => $locale, 'key' => $key, 'detail' => $detail];
    }
}
