<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Command;

use InvalidArgumentException;
use Nowo\TranslationYamlToolsBundle\Service\FrameworkTranslationPathsResolver;
use Nowo\TranslationYamlToolsBundle\Service\TranslationYamlCatalog;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;

use function in_array;
use function sprintf;

/**
 * Shared helpers for interactive domain/locale selection and path reporting.
 */
abstract class AbstractTranslationYamlCommand extends Command
{
    public function __construct(
        protected readonly TranslationYamlCatalog $catalog,
        protected readonly FrameworkTranslationPathsResolver $pathsResolver,
    ) {
        parent::__construct();
    }

    /**
     * @return array{domain: string, locale: string}
     */
    protected function resolveDomainAndLocale(
        InputInterface $input,
        OutputInterface $output,
        ?string $domainOption,
        ?string $localeOption,
    ): array {
        $domains = $this->catalog->listDomains();
        if ($domains === []) {
            throw new RuntimeException('No translation YAML files found. Check translator paths (translator.default_path, framework.translator.paths) and that files match domain.locale.yaml.');
        }

        $domain = $domainOption;
        if ($domain === null || $domain === '') {
            $domain = $this->askChoice($input, $output, 'Select domain', $domains);
        } elseif (!in_array($domain, $domains, true)) {
            throw new InvalidArgumentException(sprintf('Unknown domain "%s". Available: %s', $domain, implode(', ', $domains)));
        }

        $locales = $this->catalog->listLocalesForDomain($domain);
        if ($locales === []) {
            throw new RuntimeException(sprintf('No locale files found for domain "%s".', $domain));
        }

        $locale = $localeOption;
        if ($locale === null || $locale === '') {
            $locale = $this->askChoice($input, $output, 'Select locale', $locales);
        } elseif (!in_array($locale, $locales, true)) {
            throw new InvalidArgumentException(sprintf('Unknown locale "%s" for domain "%s". Available: %s', $locale, $domain, implode(', ', $locales)));
        }

        return ['domain' => $domain, 'locale' => $locale];
    }

    protected function printConfiguredPaths(OutputInterface $output): void
    {
        $output->writeln('<info>Translation directories in use:</info>');
        foreach ($this->pathsResolver->resolveTranslationDirectories() as $dir) {
            $output->writeln(' - ' . $dir);
        }
        foreach ($this->pathsResolver->describeResolutionSources() as $line) {
            $output->writeln('<comment>' . $line . '</comment>');
        }
    }

    /**
     * @param list<string> $choices
     */
    private function askChoice(InputInterface $input, OutputInterface $output, string $questionText, array $choices): string
    {
        if ($input->isInteractive()) {
            /** @var QuestionHelper $helper */
            $helper   = $this->getHelper('question');
            $question = new ChoiceQuestion($questionText . ': ', $choices);
            $question->setMultiselect(false);

            return (string) $helper->ask($input, $output, $question);
        }

        throw new RuntimeException('Non-interactive mode requires both --domain and --locale (use --no-interaction only with explicit options).');
    }
}
