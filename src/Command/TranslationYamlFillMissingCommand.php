<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Command;

use InvalidArgumentException;
use Nowo\TranslationYamlToolsBundle\MachineTranslation\MachineTranslatorInterface;
use Nowo\TranslationYamlToolsBundle\Service\DotKeyTreeAnalyzer;
use Nowo\TranslationYamlToolsBundle\Service\FrameworkTranslationPathsResolver;
use Nowo\TranslationYamlToolsBundle\Service\TranslationDefaultLocaleResolver;
use Nowo\TranslationYamlToolsBundle\Service\TranslationYamlCatalog;
use Nowo\TranslationYamlToolsBundle\Service\TranslationYamlFileHandler;
use Nowo\TranslationYamlToolsBundle\Service\YamlArraySorter;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\Question;
use Throwable;

use function array_key_exists;
use function count;
use function in_array;
use function is_string;
use function sprintf;

use const PATHINFO_EXTENSION;
use const SORT_STRING;

/**
 * Fills missing keys in a target locale from the default/source locale using a machine translator (Google, DeepL, or LibreTranslate).
 */
#[AsCommand(
    name: 'nowo:translation-yaml:fill-missing',
    description: 'Fill missing translation keys in a target locale YAML using the configured machine translator',
)]
final class TranslationYamlFillMissingCommand extends AbstractTranslationYamlCommand
{
    public function __construct(
        TranslationYamlCatalog $catalog,
        FrameworkTranslationPathsResolver $pathsResolver,
        private readonly TranslationYamlFileHandler $fileHandler,
        private readonly TranslationDefaultLocaleResolver $defaultLocaleResolver,
        private readonly DotKeyTreeAnalyzer $dotKeyTreeAnalyzer,
        private readonly YamlArraySorter $yamlArraySorter,
        private readonly MachineTranslatorInterface $machineTranslator,
        private readonly ?string $bundleDefaultLocaleOverride,
        private readonly int $configuredIndent,
        private readonly string $machineTranslatorBackend,
        private readonly bool $machineTranslatorPerLocaleEnabled,
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
            ->addOption('source-locale', null, InputOption::VALUE_REQUIRED, 'Source locale (defaults to Symfony default locale unless overridden in bundle config)')
            ->addOption('target-locale', null, InputOption::VALUE_REQUIRED, 'Locale file to update')
            ->addOption('tree', null, InputOption::VALUE_NONE, 'Write result as nested YAML tree when dot-keys allow it')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show missing keys only; do not translate or write')
            ->addOption('inline', null, InputOption::VALUE_NONE, 'Write YAML in compact inline (flow) style instead of expanded blocks');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->printConfiguredPaths($output);
        $output->writeln('');

        $defaultLocale = $this->defaultLocaleResolver->resolve($this->bundleDefaultLocaleOverride);
        $output->writeln('<info>Default source locale:</info> ' . $defaultLocale . ' (override with <comment>nowo_translation_yaml_tools.default_locale</comment> or <comment>--source-locale</comment>)');
        $mtLine = $this->machineTranslatorBackend;
        if ($this->machineTranslatorPerLocaleEnabled) {
            $mtLine .= ' <comment>(default; per-locale overrides in machine_translator_by_locale)</comment>';
        }
        $output->writeln('<info>Machine translator:</info> ' . $mtLine);
        $output->writeln('');

        $domains = $this->catalog->listDomains();
        $output->writeln('<info>Domains found:</info> ' . (count($domains) === 0 ? '(none)' : implode(', ', $domains)));
        $output->writeln('');

        $domainOption = $input->getOption('domain');
        $domain       = is_string($domainOption) && $domainOption !== '' ? $domainOption : null;
        if ($domain === null) {
            if (!$input->isInteractive()) {
                throw new RuntimeException('Non-interactive mode requires --domain.');
            }
            /** @var \Symfony\Component\Console\Helper\QuestionHelper $helper */
            $helper = $this->getHelper('question');
            $domain = (string) $helper->ask($input, $output, new ChoiceQuestion('Select domain: ', $this->catalog->listDomains()));
        }

        $knownDomains = $this->catalog->listDomains();
        if (!in_array($domain, $knownDomains, true)) {
            throw new InvalidArgumentException(sprintf('Unknown domain "%s". Known domains: %s', $domain, count($knownDomains) === 0 ? '(none)' : implode(', ', $knownDomains)));
        }

        $sourceLocaleOption = $input->getOption('source-locale');
        $sourceLocale       = is_string($sourceLocaleOption) && $sourceLocaleOption !== '' ? $sourceLocaleOption : null;
        if ($sourceLocale === null) {
            $sourceLocale = $defaultLocale;
        }

        $targetLocaleOption = $input->getOption('target-locale');
        $targetLocale       = is_string($targetLocaleOption) && $targetLocaleOption !== '' ? $targetLocaleOption : null;
        if ($targetLocale === null) {
            if (!$input->isInteractive()) {
                throw new RuntimeException('Non-interactive mode requires --target-locale.');
            }
            /** @var \Symfony\Component\Console\Helper\QuestionHelper $helper */
            $helper       = $this->getHelper('question');
            $question     = new Question(sprintf('Target locale to fill (source is %s): ', $sourceLocale));
            $targetLocale = (string) $helper->ask($input, $output, $question);
        }

        if ($sourceLocale === $targetLocale) {
            $output->writeln('<error>Source and target locale must differ (both are "' . $targetLocale . '").</error>');

            return Command::FAILURE;
        }

        $sourcePath = $this->catalog->resolveFileForDomainLocale($domain, $sourceLocale);
        if ($sourcePath === null) {
            $output->writeln(sprintf('<error>Source file not found for domain "%s" and locale "%s".</error>', $domain, $sourceLocale));

            return Command::FAILURE;
        }

        $targetPath   = $this->catalog->resolveFileForDomainLocale($domain, $targetLocale);
        $targetExists = $targetPath !== null;
        if (!$targetExists) {
            $targetPath = $this->guessTargetPathForNewFile($domain, $targetLocale, $sourcePath);
            $output->writeln(sprintf('<comment>Target file does not exist; will create: %s</comment>', $targetPath));
        }

        $sourceData = $this->fileHandler->loadFile($sourcePath);
        $targetData = $targetExists ? $this->fileHandler->loadFile((string) $targetPath) : [];

        $flatSource = $this->dotKeyTreeAnalyzer->flatten($sourceData);
        $flatTarget = $this->dotKeyTreeAnalyzer->flatten($targetData);

        $missing = [];
        foreach ($flatSource as $key => $value) {
            if (!array_key_exists($key, $flatTarget)) {
                $missing[$key] = $value;
            }
        }

        if ($missing === []) {
            $output->writeln('<info>No missing keys.</info>');

            return Command::SUCCESS;
        }

        $output->writeln(sprintf('<info>Missing keys:</info> %d', count($missing)));
        foreach (array_keys($missing) as $key) {
            $output->writeln(' - ' . $key);
        }

        if ((bool) $input->getOption('dry-run')) {
            $output->writeln('<comment>Dry-run: no API calls and no file write.</comment>');

            return Command::SUCCESS;
        }

        $stringCount = 0;
        foreach ($missing as $value) {
            if (is_string($value)) {
                ++$stringCount;
            }
        }

        $progress = null;
        if ($stringCount > 0 && $output->isDecorated() && !$output->isVerbose()) {
            // ProgressBar needs a decorated TTY; CommandTester often runs non-decorated in CI.
            // @codeCoverageIgnoreStart
            $output->writeln(sprintf('<info>Translating %d string value(s)…</info>', $stringCount));
            $progress = new ProgressBar($output, $stringCount);
            $progress->start();
        // @codeCoverageIgnoreEnd
        } elseif ($stringCount > 0) {
            $output->writeln(sprintf('<info>Translating %d string value(s)…</info>', $stringCount));
        }

        try {
            foreach ($missing as $key => $value) {
                if (is_string($value)) {
                    if ($output->isVerbose()) {
                        $output->writeln(sprintf(' <comment>%s</comment>', $key));
                    }
                    try {
                        $flatTarget[$key] = $this->machineTranslator->translate($value, $sourceLocale, $targetLocale);
                    } catch (Throwable $e) {
                        $output->writeln('');
                        $output->writeln(sprintf('<error>Translation failed for key "%s": %s</error>', $key, $e->getMessage()));

                        return Command::FAILURE;
                    }
                    $progress?->advance();
                } else {
                    $flatTarget[$key] = $value;
                }
            }
        } finally {
            if ($progress instanceof ProgressBar) {
                // @codeCoverageIgnoreStart
                $progress->finish();
                $output->writeln('');
                // @codeCoverageIgnoreEnd
            }
        }

        ksort($flatTarget, SORT_STRING);

        $asTree = (bool) $input->getOption('tree');
        if ($asTree) {
            $conflict = $this->dotKeyTreeAnalyzer->treeConversionConflict($flatTarget);
            if ($conflict !== null) {
                $output->writeln('<error>Cannot write as tree.</error>');
                $output->writeln($conflict);

                return Command::FAILURE;
            }
            $toWrite = $this->dotKeyTreeAnalyzer->unflatten($flatTarget);
        } else {
            /** @var array<string, mixed> $toWrite */
            $toWrite = $flatTarget;
        }

        $toWrite          = $this->yamlArraySorter->sortAssociativeRecursive($toWrite);
        $beforeWriteCount = count($flatTarget);
        $output->writeln(sprintf('<info>Leaf keys (before write, flat map):</info> %d', $beforeWriteCount));
        $afterWriteCount = $this->dotKeyTreeAnalyzer->countFlattenedLeaves($toWrite);
        $output->writeln(sprintf('<info>Leaf keys (after transform):</info> %d', $afterWriteCount));
        $preserveError = $this->dotKeyTreeAnalyzer->verifyFlattenedLeavesPreserved($flatTarget, $toWrite);
        if ($preserveError !== null) {
            $output->writeln('<error>Leaf key integrity check failed; file not written.</error>');
            $output->writeln('<error>' . $preserveError . '</error>');

            return Command::FAILURE;
        }
        $output->writeln('<info>Leaf key counts match (round-trip).</info>');
        $asInline = (bool) $input->getOption('inline');
        $this->fileHandler->dumpToFile((string) $targetPath, $toWrite, $this->configuredIndent, $asInline);
        $output->writeln(sprintf(
            '<info>Wrote %d key(s) to %s (%s)</info>',
            count($flatTarget),
            $targetPath,
            $asInline ? 'inline flow' : 'block',
        ));

        return Command::SUCCESS;
    }

    private function guessTargetPathForNewFile(string $domain, string $locale, string $sourcePath): string
    {
        $dirs = $this->pathsResolver->resolveTranslationDirectories();
        $base = $dirs[0] ?? rtrim(getcwd() ?: '.', '/') . '/translations';
        $ext  = strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION));
        if ($ext === '') {
            $ext = 'yaml';
        }

        return sprintf('%s/%s.%s.%s', $base, $domain, $locale, $ext);
    }
}
