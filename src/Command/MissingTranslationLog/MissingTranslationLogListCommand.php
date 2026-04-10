<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Command\MissingTranslationLog;

use DateTimeInterface;
use Nowo\TranslationYamlToolsBundle\Entity\MissingTranslationLogStatus;
use Nowo\TranslationYamlToolsBundle\Repository\MissingTranslationLogRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function sprintf;

#[AsCommand(
    name: 'nowo:translation-yaml:missing-log-list',
    description: 'List missing translation records stored in the database (requires missing_translation_log.enabled)',
)]
final class MissingTranslationLogListCommand extends Command
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
            ->addOption('status', null, InputOption::VALUE_REQUIRED, 'Filter: pending, added, validated', 'pending')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max rows', '100');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $status = MissingTranslationLogStatus::tryFrom((string) $input->getOption('status'));
        if ($status === null) {
            $io->error('Invalid --status; use pending, added, or validated.');

            return Command::INVALID;
        }

        $limit = max(1, min(5000, (int) $input->getOption('limit')));
        $rows  = $this->repository->findByStatus($status, $limit);

        if ($rows === []) {
            $io->success(sprintf('No rows with status "%s".', $status->value));

            return Command::SUCCESS;
        }

        $io->title(sprintf('Missing translation log (%s)', $status->value));
        $table = [];
        foreach ($rows as $row) {
            $table[] = [
                $row->getId(),
                $row->getLocale(),
                $row->getDomain(),
                $row->getMessageId(),
                $row->getCallSite() ?? '—',
                $row->getHitCount(),
                $row->getLastSeenAt()->format(DateTimeInterface::ATOM),
            ];
        }
        $io->table(['id', 'locale', 'domain', 'message_id', 'call_site', 'hits', 'last_seen'], $table);

        return Command::SUCCESS;
    }
}
