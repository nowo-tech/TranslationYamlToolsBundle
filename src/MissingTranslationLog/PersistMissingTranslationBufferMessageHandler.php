<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\MissingTranslationLog;

use Nowo\TranslationYamlToolsBundle\Repository\MissingTranslationLogRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Persists a buffer dispatched by `missing_translation_log.async_persist` (strategy messenger).
 *
 * Since 1.19 (REQ-RUNTIME-003 rules 7 and 8): every key is an atomic upsert (no duplicate row, whatever the order or
 * the number of workers), a deadlock or lock wait timeout is replayed in place and then rethrown, so Messenger retries
 * the message instead of losing it. A message handled twice (redelivered after its commit) adds its hits twice: the
 * hit count is a diagnostic counter, see docs/CONCURRENCY.md.
 */
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
