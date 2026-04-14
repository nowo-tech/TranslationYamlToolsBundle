<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Service;

use Throwable;

use function array_key_exists;
use function array_map;
use function array_slice;
use function count;
use function in_array;
use function sprintf;

/**
 * Builds a per-domain report of tree convertibility, key sorting, and missing keys vs a source locale.
 */
final class TranslationYamlAuditor
{
    public function __construct(
        private readonly TranslationYamlCatalog $catalog,
        private readonly TranslationYamlFileHandler $fileHandler,
        private readonly DotKeyTreeAnalyzer $dotKeyTreeAnalyzer,
        private readonly YamlArraySorter $yamlArraySorter,
    ) {
    }

    /**
     * @return list<array{
     *     domain: string,
     *     all_ok: bool,
     *     source_error: ?string,
     *     locales: list<array{
     *         locale: string,
     *         path: string,
     *         yaml_error: ?string,
     *         tree_ok: bool,
     *         tree_conflict_count: int,
     *         tree_conflict_samples: list<string>,
     *         sorted: bool,
     *         missing_vs_source: ?int
     *     }>
     * }>
     */
    public function audit(string $sourceLocale, ?string $onlyDomain = null): array
    {
        $domains = $this->catalog->listDomains();
        if ($onlyDomain !== null && $onlyDomain !== '') {
            $domains = in_array($onlyDomain, $domains, true) ? [$onlyDomain] : [];
        }

        $out = [];
        foreach ($domains as $domain) {
            $out[] = $this->auditDomain($domain, $sourceLocale);
        }

        return $out;
    }

    /**
     * @return array{
     *     domain: string,
     *     all_ok: bool,
     *     source_error: ?string,
     *     locales: list<array{
     *         locale: string,
     *         path: string,
     *         yaml_error: ?string,
     *         tree_ok: bool,
     *         tree_conflict_count: int,
     *         tree_conflict_samples: list<string>,
     *         sorted: bool,
     *         missing_vs_source: ?int
     *     }>
     * }
     */
    private function auditDomain(string $domain, string $sourceLocale): array
    {
        $locales = $this->catalog->listLocalesForDomain($domain);

        $sourcePath  = $this->catalog->resolveFileForDomainLocale($domain, $sourceLocale);
        $sourceFlat  = [];
        $sourceError = null;
        if ($sourcePath === null) {
            $sourceError = sprintf('No translation file for domain "%s" and source locale "%s".', $domain, $sourceLocale);
        } else {
            try {
                $sourceData = $this->fileHandler->loadFile($sourcePath);
                $sourceFlat = $this->dotKeyTreeAnalyzer->flatten($sourceData);
            } catch (Throwable $e) {
                $sourceError = $e->getMessage();
            }
        }

        $canCompareMissing = $sourceError === null;

        $localeRows = [];
        foreach ($locales as $locale) {
            $path = $this->catalog->resolveFileForDomainLocale($domain, $locale);
            if ($path === null) {
                continue;
            }

            $row = [
                'locale'                => $locale,
                'path'                  => $path,
                'yaml_error'            => null,
                'tree_ok'               => true,
                'tree_conflict_count'   => 0,
                'tree_conflict_samples' => [],
                'sorted'                => true,
                'missing_vs_source'     => null,
            ];

            try {
                $data = $this->fileHandler->loadFile($path);
            } catch (Throwable $e) {
                $row['yaml_error'] = $e->getMessage();
                $row['tree_ok']    = false;
                $row['sorted']     = false;
                $localeRows[]      = $row;
                continue;
            }

            $flat                         = $this->dotKeyTreeAnalyzer->flatten($data);
            $conflicts                    = $this->dotKeyTreeAnalyzer->collectTreeConversionConflicts($flat);
            $row['tree_ok']               = $conflicts === [];
            $row['tree_conflict_count']   = count($conflicts);
            $row['tree_conflict_samples'] = array_slice(
                array_map(
                    static fn (array $c): string => sprintf('"%s" is a leaf and also a prefix of "%s"', $c['leaf_key'], $c['blocked_key']),
                    $conflicts,
                ),
                0,
                4,
            );
            $row['sorted'] = $this->yamlArraySorter->isRecursivelySorted($data);

            if ($canCompareMissing && $locale !== $sourceLocale) {
                $missing = 0;
                foreach (array_keys($sourceFlat) as $k) {
                    if (!array_key_exists($k, $flat)) {
                        ++$missing;
                    }
                }
                $row['missing_vs_source'] = $missing;
            }

            $localeRows[] = $row;
        }

        $allOk = $sourceError === null;
        foreach ($localeRows as $r) {
            if ($r['yaml_error'] !== null) {
                $allOk = false;
            }
            if (!$r['tree_ok']) {
                $allOk = false;
            }
            if (!$r['sorted']) {
                $allOk = false;
            }
            if ($r['missing_vs_source'] !== null && $r['missing_vs_source'] > 0) {
                $allOk = false;
            }
        }

        return [
            'domain'       => $domain,
            'all_ok'       => $allOk,
            'source_error' => $sourceError,
            'locales'      => $localeRows,
        ];
    }
}
