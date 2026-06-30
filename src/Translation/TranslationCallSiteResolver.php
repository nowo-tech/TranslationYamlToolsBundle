<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Translation;

use function is_int;
use function is_string;
use function strlen;

use const DEBUG_BACKTRACE_IGNORE_ARGS;

/**
 * Resolves a "caller path" (absolute file path + line) for a missing translation lookup.
 *
 * Walks debug_backtrace and skips Symfony translation internals, this bundle's recorder/decorator,
 * and the Twig translation extension so the first plausible frame (controller, template compile file, command, test, …) wins.
 */
final class TranslationCallSiteResolver
{
    public static function resolve(): ?string
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 48);

        return self::pickCallSiteFromTrace($trace);
    }

    /**
     * @param list<array<string, mixed>> $trace
     */
    private static function pickCallSiteFromTrace(array $trace): ?string
    {
        foreach ($trace as $frame) {
            $file = $frame['file'] ?? null;
            if (!is_string($file) || $file === '') {
                continue;
            }

            $normalized = str_replace('\\', '/', $file);
            if (self::shouldSkipPath($normalized)) {
                continue;
            }

            $line   = $frame['line'] ?? 0;
            $lineNo = is_int($line) ? $line : 0;
            $site   = $file . ':' . $lineNo;

            return strlen($site) <= 1024 ? $site : substr($site, 0, 1021) . '...';
        }

        return null;
    }

    private static function shouldSkipPath(string $normalized): bool
    {
        foreach ([
            'TranslationCallSiteResolver.php',
            'MissingTranslationLogCallSiteBuilder.php',
            'RecordingTranslatorDecorator.php',
            'DoctrineMissingTranslationRecorder.php',
        ] as $base) {
            if (str_ends_with($normalized, '/' . $base)) {
                return true;
            }
        }

        $dirNeedles = [
            '/symfony/translation/',
            '/symfony/twig-bridge/Extension/TranslationExtension.php',
            '/symfony/contracts/Translation/TranslatorTrait.php',
        ];

        foreach ($dirNeedles as $needle) {
            if (str_contains($normalized, $needle)) {
                return true;
            }
        }

        return false;
    }
}
