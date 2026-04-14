<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\MissingTranslationLog;

use Nowo\TranslationYamlToolsBundle\Repository\MissingTranslationLogRepository;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Buffers missing keys during a request and flushes aggregated rows on kernel terminate.
 */
final class DoctrineMissingTranslationRecorder implements MissingTranslationRecorderInterface, ResetInterface
{
    /**
     * @var array<string, array{hits: int, messageId: string, domain: string, locale: string, callSite: ?string}>
     */
    private array $buffer = [];

    /**
     * @param object|null $messageBus Symfony\Component\Messenger\MessageBusInterface when symfony/messenger is installed
     */
    public function __construct(
        private readonly MissingTranslationLogRepository $repository,
        private readonly ?object $messageBus = null,
        private readonly bool $asyncPersist = false,
        private readonly string $asyncPersistStrategy = 'messenger',
        private readonly ?EventDispatcherInterface $eventDispatcher = null,
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function record(string $id, string $domain, string $locale, ?string $callSite = null): void
    {
        if ($locale === '') {
            return;
        }

        $key = hash('sha256', $locale . "\0" . $domain . "\0" . $id);

        if (!isset($this->buffer[$key])) {
            $this->buffer[$key] = [
                'hits'      => 0,
                'messageId' => $id,
                'domain'    => $domain,
                'locale'    => $locale,
                'callSite'  => null,
            ];
        }

        if ($callSite !== null && $callSite !== '') {
            $this->buffer[$key]['callSite'] = $callSite;
        }

        ++$this->buffer[$key]['hits'];
    }

    public function reset(): void
    {
        $this->buffer = [];
    }

    #[AsEventListener(event: KernelEvents::TERMINATE)]
    public function flushBuffer(): void
    {
        if ($this->buffer === []) {
            return;
        }

        $snapshot     = $this->buffer;
        $this->buffer = [];

        if ($this->asyncPersist) {
            if ($this->asyncPersistStrategy === 'messenger'
                && $this->messageBus !== null
                && interface_exists(\Symfony\Component\Messenger\MessageBusInterface::class)
                && $this->messageBus instanceof \Symfony\Component\Messenger\MessageBusInterface) {
                $this->messageBus->dispatch(new MissingTranslationBufferMessage($snapshot));

                return;
            }

            if ($this->asyncPersistStrategy === 'event_dispatcher' && $this->eventDispatcher instanceof \Symfony\Contracts\EventDispatcher\EventDispatcherInterface) {
                $this->eventDispatcher->dispatch(new MissingTranslationBufferEvent($snapshot));

                return;
            }
        }

        $this->repository->persistBuffer($snapshot);
    }
}
