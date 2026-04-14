<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Command\MissingTranslationLog;

use Nowo\TranslationYamlToolsBundle\Entity\MissingTranslationLogStatus;
use Nowo\TranslationYamlToolsBundle\Repository\MissingTranslationLogRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function sprintf;

#[AsCommand(
    name: 'nowo:translation-yaml:missing-log-mark-added',
    description: 'Mark a missing-translation row as added (translation file updated)',
)]
final class MissingTranslationLogMarkAddedCommand extends Command
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
        $this->addArgument('id', InputArgument::REQUIRED, 'Database id (from missing-log-list)');
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

        $row->setStatus(MissingTranslationLogStatus::Added);
        $this->repository->getEntityManager()->flush();

        $io->success(sprintf('Row %d marked as added (%s / %s / %s).', $id, $row->getLocale(), $row->getDomain(), $row->getMessageId()));

        return Command::SUCCESS;
    }
}
