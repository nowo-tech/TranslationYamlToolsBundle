<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\MissingTranslationLog;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched on flush when missing_translation_log.async_persist is true and async_persist_strategy is event_dispatcher.
 *
 * A built-in listener persists to Doctrine last (priority -1024) unless propagation was stopped earlier
 * (e.g. your listener enqueued the buffer and called stopPropagation() to skip the default DB write).
 */
final class MissingTranslationBufferEvent extends Event
{
    /**
     * @param array<string, array{hits: int, messageId: string, domain: string, locale: string, callSite: ?string}> $buffer
     */
    public function __construct(
        public readonly array $buffer,
    ) {
    }
}
