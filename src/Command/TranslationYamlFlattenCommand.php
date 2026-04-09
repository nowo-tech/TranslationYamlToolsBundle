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

use const SORT_STRING;

/**
 * Flattens nested translation YAML into a single-level map with dot-separated keys (inverse of tree layout).
 */
#[AsCommand(
    name: 'nowo:translation-yaml:flatten',
    description: 'Flatten nested YAML translation keys to dot paths (e.g. demo.title) at the file root',
)]
final class TranslationYamlFlattenCommand extends AbstractTranslationYamlCommand
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
            ->addOption('domain', 'd', InputOption::VALUE_REQUIRED, 'Translation domain')
            ->addOption('locale', 'l', InputOption::VALUE_REQUIRED, 'Locale')
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
            $output->writeln(sprintf('<error>No translation file found for domain "%s" and locale "%s".</error>', $domain, $locale));

            return Command::FAILURE;
        }

        $output->writeln(sprintf('<info>File:</info> %s', $path));

        $data        = $this->fileHandler->loadFile($path);
        $flat        = $this->dotKeyTreeAnalyzer->flatten($data);
        $beforeCount = count($flat);
        $output->writeln(sprintf('<info>Leaf keys (before transform):</info> %d', $beforeCount));

        $expectedLeaves = $flat;
        ksort($flat, SORT_STRING);

        $afterCount = count($flat);
        $output->writeln(sprintf('<info>Leaf keys (after transform):</info> %d', $afterCount));
        $preserveError = $this->dotKeyTreeAnalyzer->verifyFlattenedLeavesPreserved($expectedLeaves, $flat);
        if ($preserveError !== null) {
            $output->writeln('<error>Leaf key integrity check failed.</error>');
            $output->writeln('<error>' . $preserveError . '</error>');

            return Command::FAILURE;
        }
        $output->writeln('<info>Leaf key counts match (round-trip).</info>');

        if ((bool) $input->getOption('dry-run')) {
            $output->writeln('<comment>Dry-run: would write flat dot keys; no file written.</comment>');

            return Command::SUCCESS;
        }

        $asInline = (bool) $input->getOption('inline');
        $this->fileHandler->dumpToFile($path, $flat, $this->configuredIndent, $asInline);
        $output->writeln('<info>Wrote flat YAML (' . ($asInline ? 'inline flow' : 'block') . ') with dot-separated keys at root.</info>');

        return Command::SUCCESS;
    }
}
