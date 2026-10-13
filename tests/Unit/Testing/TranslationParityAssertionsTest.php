<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Tests\Unit\Testing;

use Nowo\TranslationYamlToolsBundle\Service\TranslationParityChecker;
use Nowo\TranslationYamlToolsBundle\Testing\TranslationParityAssertions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\ExpectationFailedException;
use PHPUnit\Framework\TestCase;

#[CoversClass(TranslationParityAssertions::class)]
#[CoversClass(TranslationParityChecker::class)]
final class TranslationParityAssertionsTest extends TestCase
{
    use TranslationParityAssertions;

    private const FIXTURES = __DIR__ . '/../../Fixtures/parity';

    public function testParityPasses(): void
    {
        self::assertTranslationKeyParity(self::FIXTURES . '/ok', 'en', locales: ['en', 'es']);
    }

    public function testParityPassesWithAllowlist(): void
    {
        self::assertTranslationKeyParity(
            self::FIXTURES . '/broken',
            allowedDifferences: ['es' => ['messages:b', 'messages:x', 'messages:y'], '*' => ['messages:c']],
        );
    }

    public function testParityFailureListsViolations(): void
    {
        try {
            self::assertTranslationKeyParity(self::FIXTURES . '/broken', 'en', checkEmpty: false, message: 'REQ-LOCALE-010.');
        } catch (ExpectationFailedException $e) {
            self::assertStringContainsString('REQ-LOCALE-010. Translation keys differ from the "en" catalogue', $e->getMessage());
            self::assertStringContainsString('es messages: missing key "b"', (string) $e->getComparisonFailure()?->getDiff());
            self::assertStringNotContainsString('empty key', (string) $e->getComparisonFailure()?->getDiff());

            return;
        }

        self::fail('Expected an assertion failure.');
    }

    public function testUntranslatedPassesWithAllowlist(): void
    {
        self::assertNoUntranslatedValues(
            self::FIXTURES . '/untranslated',
            'en',
            ['messages'],
            allowedSameAsSource: ['*' => ['messages:name', 'messages:sentence']],
        );
    }

    public function testUntranslatedFailure(): void
    {
        try {
            self::assertNoUntranslatedValues(self::FIXTURES . '/untranslated');
        } catch (ExpectationFailedException $e) {
            self::assertStringContainsString('Translate these values', $e->getMessage());
            self::assertStringContainsString('es messages: untranslated key', (string) $e->getComparisonFailure()?->getDiff());

            return;
        }

        self::fail('Expected an assertion failure.');
    }
}
