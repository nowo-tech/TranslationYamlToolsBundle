<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\MissingTranslationLog;

/** Snapshot of buffered missing-key rows to persist (sync or via Messenger). */
final class MissingTranslationBufferMessage
{
    /**
     * @param array<string, array{hits: int, messageId: string, domain: string, locale: string, callSite: ?string, requestRoute: ?string, requestMethod: ?string, requestPath: ?string}> $buffer
     */
    public function __construct(
        public readonly array $buffer,
    ) {
    }
}
