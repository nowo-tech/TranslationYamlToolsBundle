<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Tests\Unit\Resources;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

use function dirname;

use const PHP_BINARY;

/**
 * REQ-I18N-002 / REQ-MAKE-004: the seven required locales ship with exactly the keys of the en catalogue.
 *
 * Runs the same script as `make validate-translations`, so CI fails on a missing locale or key drift.
 */
#[CoversNothing]
final class TranslationCataloguesTest extends TestCase
{
    public function testRequiredLocalesHaveKeyParityWithEnglish(): void
    {
        $script = dirname(__DIR__, 3) . '/.scripts/validate-translations.php';
        $output = [];
        $code   = 0;
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' 2>&1', $output, $code);

        self::assertSame(0, $code, implode("\n", $output));
        self::assertStringContainsString('OK — 7 locales', implode("\n", $output));
    }
}
