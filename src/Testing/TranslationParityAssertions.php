<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Testing;

use Nowo\TranslationYamlToolsBundle\Service\TranslationParityChecker;
use PHPUnit\Framework\Assert;

use function sprintf;

/**
 * PHPUnit assertions for translation YAML catalogues (host test suites only; requires phpunit/phpunit).
 *
 * Allowlists map a locale (or `*` for every locale) to `domain:key` patterns (`fnmatch()` syntax, e.g. `messages:legal.*`).
 *
 * ```php
 * final class TranslationParityTest extends TestCase
 * {
 *     use TranslationParityAssertions;
 *
 *     public function testParity(): void
 *     {
 *         self::assertTranslationKeyParity(__DIR__ . '/../translations', 'en', locales: ['en', 'es', 'fr']);
 *     }
 * }
 * ```
 */
trait TranslationParityAssertions
{
    /**
     * Every locale ships exactly the source locale's keys (no missing, no extra) and no empty values.
     *
     * @param list<string>|null $domains null = every domain found in the directory
     * @param list<string>|null $locales expected locales (a missing file reports every key); null = locales found in the directory
     * @param array<string, list<string>> $allowedDifferences locale|"*" => "domain:key" patterns allowed to be missing / extra / empty
     */
    public static function assertTranslationKeyParity(
        string $translationDir,
        string $sourceLocale = 'en',
        ?array $domains = null,
        ?array $locales = null,
        array $allowedDifferences = [],
        bool $checkEmpty = true,
        string $message = '',
    ): void {
        $violations = (new TranslationParityChecker())->checkParity($translationDir, $sourceLocale, $domains, $locales, $allowedDifferences, $checkEmpty);

        Assert::assertSame(
            [],
            TranslationParityChecker::format($violations),
            self::translationParityMessage($message, sprintf('Translation keys differ from the "%s" catalogue (fix the YAML or allowlist the key).', $sourceLocale)),
        );
    }

    /**
     * No locale keeps a source-locale value verbatim (multi-word values only by default; `%` / `{` placeholders skipped).
     *
     * @param list<string>|null $domains null = every domain found in the directory
     * @param list<string>|null $locales null = locales found in the directory
     * @param array<string, list<string>> $allowedSameAsSource locale|"*" => "domain:key" patterns allowed to equal the source (names, commands)
     */
    public static function assertNoUntranslatedValues(
        string $translationDir,
        string $sourceLocale = 'en',
        ?array $domains = null,
        ?array $locales = null,
        array $allowedSameAsSource = [],
        bool $multiWordOnly = true,
        string $message = '',
    ): void {
        $violations = (new TranslationParityChecker())->checkUntranslated($translationDir, $sourceLocale, $domains, $locales, $allowedSameAsSource, $multiWordOnly);

        Assert::assertSame(
            [],
            TranslationParityChecker::format($violations),
            self::translationParityMessage($message, sprintf('Translate these values (or allowlist the key when it is a name or a command, same as "%s").', $sourceLocale)),
        );
    }

    /**
     * Every literal key PHP passes to the translator (`->trans('a.b')`, default domain) or to a flash
     * (`addFlash('type', 'a.b')`) exists in the `$domain.$locale` catalogue. `$minimumChecked` guards
     * against a scan that silently finds nothing (wrong directory or patterns).
     *
     * @param array<string, list<string>> $allowed "*" => "domain:key" patterns to ignore
     */
    public static function assertLiteralKeysExist(
        string $sourceDir,
        string $translationDir,
        string $locale = 'en',
        string $domain = 'messages',
        int $minimumChecked = 1,
        array $allowed = [],
        string $message = '',
    ): void {
        $result = (new TranslationParityChecker())->checkLiteralKeys($sourceDir, $translationDir, $locale, $domain, $allowed);

        Assert::assertGreaterThanOrEqual(
            $minimumChecked,
            $result['checked'],
            self::translationParityMessage($message, sprintf('The scan of "%s" found %d literal translation key(s); check the source directory.', $sourceDir, $result['checked'])),
        );
        Assert::assertSame(
            [],
            TranslationParityChecker::format($result['violations']),
            self::translationParityMessage($message, sprintf('Add these keys to the "%s" %s catalogue (and every locale).', $domain, $locale)),
        );
    }

    private static function translationParityMessage(string $message, string $default): string
    {
        return $message === '' ? $default : $message . ' ' . $default;
    }
}
