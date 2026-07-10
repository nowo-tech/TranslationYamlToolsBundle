<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Command;

use Nowo\TranslationYamlToolsBundle\Service\DotKeyTreeAnalyzer;
use Nowo\TranslationYamlToolsBundle\Service\FrameworkTranslationPathsResolver;
use Nowo\TranslationYamlToolsBundle\Service\TranslationYamlCatalog;
use Nowo\TranslationYamlToolsBundle\Service\TranslationYamlFileHandler;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function count;
use function in_array;
use function sprintf;

/**
 * Rewrites a domain locale YAML file into a nested tree when dot-keys are structurally valid.
 */
#[AsCommand(
    name: 'nowo:translation-yaml:tree',
    description: 'Convert a Symfony YAML translation file to nested tree form (validates dot-key structure first)',
)]
final class TranslationYamlTreeCommand extends AbstractTranslationYamlCommand
{
    public function __construct(
        TranslationYamlCatalog $catalog,
        FrameworkTranslationPathsResolver $pathsResolver,
        private readonly DotKeyTreeAnalyzer $dotKeyTreeAnalyzer,
        private readonly TranslationYamlFileHandler $fileHandler,
        private readonly int $configuredIndent,
        private readonly string $configuredLeafPrefixSuffix,
    ) {
        parent::__construct($catalog, $pathsResolver);
    }

    /**
     * {@inheritdoc}
     */
    protected function configure(): void
    {
        $this
            ->addOption('domain', 'd', InputOption::VALUE_REQUIRED, 'Translation domain (e.g. messages)')
            ->addOption('locale', 'l', InputOption::VALUE_OPTIONAL, 'Locale (omit to convert every locale file for the domain)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Analyse only; do not write the file')
            ->addOption('inline', null, InputOption::VALUE_NONE, 'Write YAML in compact inline (flow) style instead of expanded blocks')
            ->addOption(
                'fix-leaf-prefix',
                null,
                InputOption::VALUE_NONE,
                'When a key is both a leaf and a prefix of another, rename those leaves by appending .<suffix> (see bundle yaml_tree_leaf_prefix_suffix, or --leaf-prefix-suffix)',
            )
            ->addOption(
                'leaf-prefix-suffix',
                null,
                InputOption::VALUE_OPTIONAL,
                'Override the configured final segment for --fix-leaf-prefix (e.g. index → key a becomes a.index)',
            );
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->printConfiguredPaths($output);
        $output->writeln('');

        $domains = $this->catalog->listDomains();
        $output->writeln('<info>Domains found:</info> ' . (count($domains) === 0 ? '(none)' : implode(', ', $domains)));
        $output->writeln('');

        $domain = $this->resolveDomain(
            $input,
            $output,
            $input->getOption('domain') ? (string) $input->getOption('domain') : null,
        );

        $localesToProcess = $this->resolveLocalesForDomainOption($input, $domain);
        $this->printLocalesBannerWhenOmittingLocaleOption($input, $output, $domain, $localesToProcess, 'converting all');

        $failed      = false;
        $dryRun      = (bool) $input->getOption('dry-run');
        $asInline    = (bool) $input->getOption('inline');
        $localeCount = count($localesToProcess);
        foreach ($localesToProcess as $index => $locale) {
            if ($localeCount > 1 && $index > 0) {
                $output->writeln('');
            }

            $path = $this->catalog->resolveFileForDomainLocale($domain, $locale);
            if ($path === null) {
                $output->writeln('<error>No file found for ' . $domain . '.' . $locale . '.*</error>');
                $failed = true;

                continue;
            }

            $output->writeln(sprintf('<info>File:</info> %s', $path));

            $data        = $this->fileHandler->loadFile($path);
            $flat        = $this->dotKeyTreeAnalyzer->flatten($data);
            $beforeCount = count($flat);
            $output->writeln(sprintf('<info>Leaf keys (before transform):</info> %d', $beforeCount));
            $conflict = $this->dotKeyTreeAnalyzer->treeConversionConflict($flat);
            if ($conflict !== null) {
                if (!(bool) $input->getOption('fix-leaf-prefix')) {
                    $output->writeln('<error>Cannot convert to tree.</error>');
                    $output->writeln($conflict);
                    $output->writeln(sprintf(
                        '<comment>Tip: use --fix-leaf-prefix to rename blocking leaves by appending .%s (bundle option yaml_tree_leaf_prefix_suffix).</comment>',
                        $this->configuredLeafPrefixSuffix,
                    ));
                    $failed = true;

                    continue;
                }

                $suffixOpt = $input->getOption('leaf-prefix-suffix');
                $suffix    = (in_array($suffixOpt, [null, false, ''], true))
                    ? $this->configuredLeafPrefixSuffix
                    : (string) $suffixOpt;
                $result = $this->dotKeyTreeAnalyzer->disambiguateLeafPrefixConflicts($flat, $suffix);
                if (isset($result['error'])) {
                    $output->writeln('<error>Cannot disambiguate leaf/prefix keys.</error>');
                    $output->writeln('<error>' . $result['error'] . '</error>');
                    $failed = true;

                    continue;
                }

                $flat = $result['flat'];
                foreach ($result['renames'] as $row) {
                    $output->writeln(sprintf(
                        '<comment>Renamed leaf key "%s" → "%s" (leaf/prefix conflict).</comment>',
                        $row['from'],
                        $row['to'],
                    ));
                }

                $conflict = $this->dotKeyTreeAnalyzer->treeConversionConflict($flat);
                if ($conflict !== null) {
                    $output->writeln('<error>Cannot convert to tree after disambiguation.</error>');
                    $output->writeln($conflict);
                    $failed = true;

                    continue;
                }
            }

            $tree       = $this->dotKeyTreeAnalyzer->unflatten($flat);
            $afterCount = $this->dotKeyTreeAnalyzer->countFlattenedLeaves($tree);
            $output->writeln(sprintf('<info>Leaf keys (after transform):</info> %d', $afterCount));
            $preserveError = $this->dotKeyTreeAnalyzer->verifyFlattenedLeavesPreserved($flat, $tree);
            if ($preserveError !== null) {
                $output->writeln('<error>Leaf key integrity check failed.</error>');
                $output->writeln('<error>' . $preserveError . '</error>');
                $failed = true;

                continue;
            }
            $output->writeln('<info>Leaf key counts match (round-trip).</info>');
            if ($dryRun) {
                $output->writeln('<comment>Dry-run: structure is valid; no file written.</comment>');

                continue;
            }

            $this->fileHandler->dumpToFile($path, $tree, $this->configuredIndent, $asInline);
            $output->writeln('<info>Wrote nested YAML (' . ($asInline ? 'inline flow' : 'block') . ') using indent of ' . $this->configuredIndent . ' spaces per level.</info>');
        }

        return $failed ? Command::FAILURE : Command::SUCCESS;
    }
}
