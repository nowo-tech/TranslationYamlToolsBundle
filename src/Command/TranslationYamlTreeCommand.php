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
            ->addOption('locale', 'l', InputOption::VALUE_REQUIRED, 'Locale (e.g. en)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Analyse only; do not write the file')
            ->addOption('inline', null, InputOption::VALUE_NONE, 'Write YAML in compact inline (flow) style instead of expanded blocks');
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

        ['domain' => $domain, 'locale' => $locale] = $this->resolveDomainAndLocale(
            $input,
            $output,
            $input->getOption('domain') ? (string) $input->getOption('domain') : null,
            $input->getOption('locale') ? (string) $input->getOption('locale') : null,
        );

        $path = $this->catalog->resolveFileForDomainLocale($domain, $locale);
        if ($path === null) {
            $output->writeln('<error>No file found for ' . $domain . '.' . $locale . '.*</error>');

            return Command::FAILURE;
        }

        $output->writeln(sprintf('<info>File:</info> %s', $path));

        $data        = $this->fileHandler->loadFile($path);
        $flat        = $this->dotKeyTreeAnalyzer->flatten($data);
        $beforeCount = count($flat);
        $output->writeln(sprintf('<info>Leaf keys (before transform):</info> %d', $beforeCount));
        $conflict = $this->dotKeyTreeAnalyzer->treeConversionConflict($flat);
        if ($conflict !== null) {
            $output->writeln('<error>Cannot convert to tree.</error>');
            $output->writeln($conflict);

            return Command::FAILURE;
        }

        $tree       = $this->dotKeyTreeAnalyzer->unflatten($flat);
        $afterCount = $this->dotKeyTreeAnalyzer->countFlattenedLeaves($tree);
        $output->writeln(sprintf('<info>Leaf keys (after transform):</info> %d', $afterCount));
        $preserveError = $this->dotKeyTreeAnalyzer->verifyFlattenedLeavesPreserved($flat, $tree);
        if ($preserveError !== null) {
            $output->writeln('<error>Leaf key integrity check failed.</error>');
            $output->writeln('<error>' . $preserveError . '</error>');

            return Command::FAILURE;
        }
        $output->writeln('<info>Leaf key counts match (round-trip).</info>');
        if ((bool) $input->getOption('dry-run')) {
            $output->writeln('<comment>Dry-run: structure is valid; no file written.</comment>');

            return Command::SUCCESS;
        }

        $asInline = (bool) $input->getOption('inline');
        $this->fileHandler->dumpToFile($path, $tree, $this->configuredIndent, $asInline);
        $output->writeln('<info>Wrote nested YAML (' . ($asInline ? 'inline flow' : 'block') . ') using indent of ' . $this->configuredIndent . ' spaces per level.</info>');

        return Command::SUCCESS;
    }
}
