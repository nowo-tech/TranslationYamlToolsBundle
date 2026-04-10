<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\MissingTranslationLog;

use Nowo\TranslationYamlToolsBundle\Repository\MissingTranslationLogRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class PersistMissingTranslationBufferMessageHandler
{
    public function __construct(
        private readonly MissingTranslationLogRepository $repository,
    ) {
    }

    public function __invoke(MissingTranslationBufferMessage $message): void
    {
        $this->repository->persistBuffer($message->buffer);
    }
}
