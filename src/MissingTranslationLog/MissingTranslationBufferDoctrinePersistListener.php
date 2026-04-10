<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\MissingTranslationLog;

use Nowo\TranslationYamlToolsBundle\Repository\MissingTranslationLogRepository;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Runs after other listeners: persists the buffer unless a listener stopped propagation (custom async path).
 */
#[AsEventListener(event: MissingTranslationBufferEvent::class, priority: -1024)]
final class MissingTranslationBufferDoctrinePersistListener
{
    public function __construct(
        private readonly MissingTranslationLogRepository $repository,
    ) {
    }

    public function __invoke(MissingTranslationBufferEvent $event): void
    {
        if ($event->isPropagationStopped()) {
            return;
        }

        $this->repository->persistBuffer($event->buffer);
    }
}
