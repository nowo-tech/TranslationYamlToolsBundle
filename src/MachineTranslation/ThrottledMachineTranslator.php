<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\MachineTranslation;

use function microtime;
use function usleep;

/**
 * Optional pacing between machine-translation HTTP calls (rate-limit friendly).
 */
final class ThrottledMachineTranslator implements MachineTranslatorInterface
{
    private float $lastCallAt = 0.0;

    public function __construct(
        private readonly MachineTranslatorInterface $inner,
        private readonly int $minIntervalMs = 0,
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function translate(string $text, string $sourceLocale, string $targetLocale): string
    {
        if ($this->minIntervalMs > 0) {
            $now  = microtime(true);
            $wait = ($this->lastCallAt + ($this->minIntervalMs / 1000.0)) - $now;
            if ($wait > 0) {
                usleep((int) round($wait * 1_000_000));
            }
            $this->lastCallAt = microtime(true);
        }

        return $this->inner->translate($text, $sourceLocale, $targetLocale);
    }
}
