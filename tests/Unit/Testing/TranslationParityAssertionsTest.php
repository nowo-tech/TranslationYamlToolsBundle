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

    private const LITERAL = __DIR__ . '/../../Fixtures/literal';

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

    public function testLiteralKeysPassWithAllowlist(): void
    {
        self::assertLiteralKeysExist(
            self::LITERAL . '/src',
            self::LITERAL . '/translations',
            minimumChecked: 4,
            allowed: ['*' => ['messages:missing.*', 'messages:flash.missing']],
        );
    }

    public function testLiteralKeysFailureNamesKeyAndFile(): void
    {
        try {
            self::assertLiteralKeysExist(self::LITERAL . '/src', self::LITERAL . '/translations', message: 'REQ-LOCALE-011.');
        } catch (ExpectationFailedException $e) {
            $diff = (string) $e->getComparisonFailure()?->getDiff();
            self::assertStringContainsString('REQ-LOCALE-011. Add these keys to the "messages" en catalogue', $e->getMessage());
            self::assertStringContainsString('en messages: unknown literal key "missing.key" in "Sub/LiteralKeysFixture.php"', $diff);
            self::assertStringContainsString('flash.missing', $diff);
            self::assertStringNotContainsString('form.only', $diff);

            return;
        }

        self::fail('Expected an assertion failure.');
    }

    public function testLiteralKeysScanThatFindsNothingFails(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageMatches('/found 0 literal translation key/');
        self::assertLiteralKeysExist(self::LITERAL . '/no-such-dir', self::LITERAL . '/translations');
    }

    public function testLiteralKeysWithoutCatalogueReportTheSource(): void
    {
        $result = (new TranslationParityChecker())->checkLiteralKeys(self::LITERAL . '/src', self::LITERAL . '/translations', 'fr');

        self::assertSame(0, $result['checked']);
        self::assertSame(['fr messages: source_missing (messages.fr.yaml is missing or empty.)'], TranslationParityChecker::format($result['violations']));
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
