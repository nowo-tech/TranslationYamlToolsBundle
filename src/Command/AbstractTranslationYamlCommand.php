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

use function implode;
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
     * Resolves the translation domain (interactive pick when missing in interactive mode).
     *
     * @throws InvalidArgumentException when the domain is unknown
     * @throws RuntimeException         when no domains exist or non-interactive without a domain
     */
    protected function resolveDomain(
        InputInterface $input,
        OutputInterface $output,
        ?string $domainOption,
    ): string {
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

        return $domain;
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
        $domain  = $this->resolveDomain($input, $output, $domainOption);
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

    /**
     * Resolves locales from --locale or, when omitted, every locale known for the domain.
     *
     * @return list<string>
     */
    protected function resolveLocalesForDomainOption(InputInterface $input, string $domain): array
    {
        $localesForDomain = $this->catalog->listLocalesForDomain($domain);
        if ($localesForDomain === []) {
            throw new RuntimeException(sprintf('No locale files found for domain "%s".', $domain));
        }

        $localeOpt = $input->getOption('locale');
        if ($localeOpt !== null && $localeOpt !== false && $localeOpt !== '') {
            $locale = (string) $localeOpt;
            if (!in_array($locale, $localesForDomain, true)) {
                throw new InvalidArgumentException(sprintf(
                    'Unknown locale "%s" for domain "%s". Available: %s',
                    $locale,
                    $domain,
                    implode(', ', $localesForDomain),
                ));
            }

            return [$locale];
        }

        return $localesForDomain;
    }

    /**
     * Prints which locales will be processed when --locale was not passed.
     *
     * @param list<string> $localesToProcess
     */
    protected function printLocalesBannerWhenOmittingLocaleOption(
        InputInterface $input,
        OutputInterface $output,
        string $domain,
        array $localesToProcess,
        string $actionDescription,
    ): void {
        $localeOpt = $input->getOption('locale');
        if ($localeOpt !== null && $localeOpt !== false && $localeOpt !== '') {
            return;
        }

        $output->writeln(sprintf(
            '<info>Locales for domain "%s" (no --locale; %s):</info> %s',
            $domain,
            $actionDescription,
            implode(', ', $localesToProcess),
        ));
        $output->writeln('');
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

        throw new RuntimeException('Non-interactive mode requires explicit options (e.g. --domain; --locale is optional and limits to one file).');
    }
}
