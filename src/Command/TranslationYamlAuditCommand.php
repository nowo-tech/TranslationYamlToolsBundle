<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Command;

use Nowo\TranslationYamlToolsBundle\Service\DotKeyTreeAnalyzer;
use Nowo\TranslationYamlToolsBundle\Service\FrameworkTranslationPathsResolver;
use Nowo\TranslationYamlToolsBundle\Service\TranslationDefaultLocaleResolver;
use Nowo\TranslationYamlToolsBundle\Service\TranslationYamlAuditor;
use Nowo\TranslationYamlToolsBundle\Service\TranslationYamlCatalog;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function array_slice;
use function count;
use function implode;
use function in_array;
use function is_string;
use function sprintf;

/**
 * Prints a short audit of translation YAML: tree convertibility, alphabetical order, and missing keys vs the source locale.
 * With `--strict`, extra keys vs the source locale and empty values also fail (exit 1).
 */
#[AsCommand(
    name: 'nowo:translation-yaml:audit',
    description: 'Audit translation YAML files (tree-safe keys, alphabetical order, missing keys vs source locale)',
)]
final class TranslationYamlAuditCommand extends AbstractTranslationYamlCommand
{
    private const STRICT_SAMPLE_LIMIT = 20;

    public function __construct(
        TranslationYamlCatalog $catalog,
        FrameworkTranslationPathsResolver $pathsResolver,
        private readonly TranslationYamlAuditor $auditor,
        private readonly TranslationDefaultLocaleResolver $defaultLocaleResolver,
        private readonly ?string $bundleDefaultLocaleOverride,
    ) {
        parent::__construct($catalog, $pathsResolver);
    }

    protected function configure(): void
    {
        $this
            ->addOption('domain', 'd', InputOption::VALUE_REQUIRED, 'Limit audit to one domain')
            ->addOption('source-locale', null, InputOption::VALUE_REQUIRED, 'Source locale for missing-key comparison (default: Symfony default locale unless overridden in bundle config)')
            ->addOption('strict', null, InputOption::VALUE_NONE, 'Also fail on keys present in a locale but not in the source locale (extra) and on empty values; lists the offending keys');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->printConfiguredPaths($output);
        $output->writeln('');

        $sourceLocale = $input->getOption('source-locale') ? (string) $input->getOption('source-locale') : $this->defaultLocaleResolver->resolve($this->bundleDefaultLocaleOverride);
        $output->writeln(sprintf('<info>Source locale (missing keys):</info> %s', $sourceLocale));
        $output->writeln('');

        $domainOpt    = $input->getOption('domain');
        $domainFilter = is_string($domainOpt) && $domainOpt !== '' ? $domainOpt : null;
        if ($domainFilter !== null) {
            $known = $this->catalog->listDomains();
            if (!in_array($domainFilter, $known, true)) {
                $output->writeln(sprintf('<error>Unknown domain "%s". Known: %s</error>', $domainFilter, $known === [] ? '(none)' : implode(', ', $known)));

                return Command::FAILURE;
            }
        }

        $reports = $this->auditor->audit($sourceLocale, $domainFilter);
        if ($reports === []) {
            $output->writeln('<comment>No domains to audit.</comment>');

            return Command::SUCCESS;
        }

        $strict   = (bool) $input->getOption('strict');
        $anyIssue = false;
        foreach ($reports as $report) {
            if ($strict ? $report['strict_ok'] : $report['all_ok']) {
                $output->writeln(sprintf('<info>✓ %s</info> — tree-safe, alphabetically sorted, no missing keys vs %s%s', $report['domain'], $sourceLocale, $strict ? ', no extra keys, no empty values' : ''));
                continue;
            }

            $anyIssue = true;
            $output->writeln(sprintf('<comment>✗ %s</comment>', $report['domain']));
            if ($report['source_error'] !== null) {
                $output->writeln(sprintf('  <error>Source:</error> %s', $report['source_error']));
            }

            foreach ($report['locales'] as $row) {
                $bullets  = [];
                $subLines = [];
                if ($row['yaml_error'] !== null) {
                    $bullets[] = sprintf('YAML error: %s', $row['yaml_error']);
                }
                if (!$row['tree_ok']) {
                    $bullets[] = sprintf(
                        'not tree-safe: %d × %s conflict(s)',
                        $row['tree_conflict_count'],
                        DotKeyTreeAnalyzer::CONFLICT_LEAF_AND_PREFIX,
                    );
                    foreach ($row['tree_conflict_samples'] as $sample) {
                        $subLines[] = $sample;
                    }
                }
                if (!$row['sorted']) {
                    $bullets[] = 'keys not alphabetically sorted (recursive)';
                }
                if ($row['missing_vs_source'] !== null && $row['missing_vs_source'] > 0) {
                    $bullets[] = sprintf('%d missing key(s) vs %s', $row['missing_vs_source'], $sourceLocale);
                    if ($strict) {
                        $subLines = [...$subLines, ...$this->samples('missing', $row['missing_keys'])];
                    }
                }
                if ($strict && $row['extra_keys'] !== []) {
                    $bullets[] = sprintf('%d extra key(s) not in %s', count($row['extra_keys']), $sourceLocale);
                    $subLines  = [...$subLines, ...$this->samples('extra', $row['extra_keys'])];
                }
                if ($strict && $row['empty_keys'] !== []) {
                    $bullets[] = sprintf('%d empty value(s)', count($row['empty_keys']));
                    $subLines  = [...$subLines, ...$this->samples('empty', $row['empty_keys'])];
                }

                if ($bullets === [] && $subLines === []) {
                    continue;
                }

                $output->writeln(sprintf('  <fg=yellow>%s</fg=yellow> (%s)', $row['locale'], $row['path']));
                foreach ($bullets as $b) {
                    $output->writeln('    - ' . $b);
                }
                foreach ($subLines as $s) {
                    $output->writeln('        · ' . $s);
                }
            }
            $output->writeln('');
        }

        return $anyIssue ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * @param list<string> $keys
     *
     * @return list<string>
     */
    private function samples(string $label, array $keys): array
    {
        $lines = [];
        foreach (array_slice($keys, 0, self::STRICT_SAMPLE_LIMIT) as $key) {
            $lines[] = sprintf('%s: %s', $label, $key);
        }
        if (count($keys) > self::STRICT_SAMPLE_LIMIT) {
            $lines[] = sprintf('%s: … and %d more', $label, count($keys) - self::STRICT_SAMPLE_LIMIT);
        }

        return $lines;
    }
}
