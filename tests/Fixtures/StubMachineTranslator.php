<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Tests\Fixtures;

use Nowo\TranslationYamlToolsBundle\MachineTranslation\MachineTranslatorInterface;

/**
 * Deterministic translator for command tests (no HTTP).
 */
final class StubMachineTranslator implements MachineTranslatorInterface
{
    public function __construct(
        private readonly string $prefix = 'T:',
        private readonly bool $throwOnSecond = false,
    ) {
    }

    private int $callCount = 0;

    public function translate(string $text, string $sourceLocale, string $targetLocale): string
    {
        ++$this->callCount;
        if ($this->throwOnSecond && $this->callCount >= 2) {
            throw new \RuntimeException('simulated API failure');
        }

        return $this->prefix . $text;
    }
}
