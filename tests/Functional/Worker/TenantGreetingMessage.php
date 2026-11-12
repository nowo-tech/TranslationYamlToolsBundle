<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Tests\Functional\Worker;

/**
 * Message of the boundary test: render the greeting for one tenant (unit $index); $fail makes the handler throw.
 */
final class TenantGreetingMessage
{
    public function __construct(
        public readonly string $tenant,
        public readonly int $index,
        public readonly bool $fail = false,
    ) {
    }
}
