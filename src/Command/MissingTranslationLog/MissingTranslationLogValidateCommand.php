<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Command\MissingTranslationLog;

use Nowo\TranslationYamlToolsBundle\Entity\MissingTranslationLogStatus;
use Nowo\TranslationYamlToolsBundle\Repository\MissingTranslationLogRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function sprintf;

#[AsCommand(
    name: 'nowo:translation-yaml:missing-log-validate',
    description: 'Mark a missing-translation row as validated (reviewed)',
)]
final class MissingTranslationLogValidateCommand extends Command
{
    public function __construct(
        private readonly MissingTranslationLogRepository $repository,
    ) {
        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    protected function configure(): void
    {
        $this
            ->addArgument('id', InputArgument::REQUIRED, 'Database id (from missing-log-list)')
            ->addOption('note', null, InputOption::VALUE_OPTIONAL, 'Optional note stored on the row');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $id = (int) $input->getArgument('id');

        $row = $this->repository->findOneById($id);
        if (!$row instanceof \Nowo\TranslationYamlToolsBundle\Entity\MissingTranslationLog) {
            $io->error(sprintf('No row with id %d.', $id));

            return Command::FAILURE;
        }

        $note = $input->getOption('note');
        if ($note !== null && $note !== '') {
            $row->setNotes((string) $note);
        }

        $row->setStatus(MissingTranslationLogStatus::Validated);
        $this->repository->getEntityManager()->flush();

        $io->success(sprintf('Row %d marked as validated (%s / %s / %s).', $id, $row->getLocale(), $row->getDomain(), $row->getMessageId()));

        return Command::SUCCESS;
    }
}
