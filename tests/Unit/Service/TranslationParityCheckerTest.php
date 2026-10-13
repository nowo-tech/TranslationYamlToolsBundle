<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Tests\Unit\Service;

use Nowo\TranslationYamlToolsBundle\Service\TranslationParityChecker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TranslationParityChecker::class)]
final class TranslationParityCheckerTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../../Fixtures/parity';

    public function testFlatHelpers(): void
    {
        $checker = new TranslationParityChecker();
        $source  = ['a' => 'One', 'b' => 'Two words', 'c' => ''];
        $target  = ['a' => 'Uno', 'b' => 'Two words', 'x' => null, 'y' => [], 'z' => ' '];

        self::assertSame(['c'], $checker->missingKeys($source, $target));
        self::assertSame(['x', 'y', 'z'], $checker->extraKeys($source, $target));
        self::assertSame(['x', 'y', 'z'], $checker->emptyKeys($target));
        self::assertSame(['c'], $checker->emptyKeys($source));
        self::assertSame(['b'], $checker->untranslatedKeys($source, $target));
        self::assertSame(['b'], $checker->untranslatedKeys(['a' => 'One', 'b' => 'Two words'], ['a' => 'Uno', 'b' => 'Two words'], false));
        self::assertSame(['a', 'b'], $checker->untranslatedKeys(['a' => 'One', 'b' => 'Two words', 'n' => 3], ['a' => 'One', 'b' => 'Two words', 'n' => 3], false));
    }

    public function testDiscoverListsDomainsAndLocales(): void
    {
        $checker = new TranslationParityChecker();

        self::assertSame(['domains' => ['messages', 'validators'], 'locales' => ['en', 'es']], $checker->discover(self::FIXTURES . '/ok'));
        self::assertSame(['domains' => [], 'locales' => []], $checker->discover(self::FIXTURES . '/does-not-exist'));
    }

    public function testParityPassesOnConsistentCatalogues(): void
    {
        self::assertSame([], (new TranslationParityChecker())->checkParity(self::FIXTURES . '/ok/', 'en'));
    }

    public function testParityReportsMissingExtraAndEmpty(): void
    {
        $lines = TranslationParityChecker::format((new TranslationParityChecker())->checkParity(self::FIXTURES . '/broken', 'en'));

        self::assertSame([
            'en messages: empty key "c"',
            'es messages: missing key "b"',
            'es messages: extra key "x"',
            'es messages: extra key "y"',
            'es messages: empty key "y"',
        ], $lines);
    }

    public function testParityHonoursAllowlistsAndEmptyToggle(): void
    {
        $checker    = new TranslationParityChecker();
        $violations = $checker->checkParity(
            self::FIXTURES . '/broken',
            'en',
            ['messages'],
            null,
            ['es' => ['messages:b', 'messages:x'], '*' => ['messages:y']],
            false,
        );

        self::assertSame([], $violations);
        self::assertTrue($checker->isAllowed(['*' => ['messages:legal.*']], 'fr', 'messages', 'legal.terms'));
        self::assertFalse($checker->isAllowed(['es' => ['messages:legal.*']], 'fr', 'messages', 'legal.terms'));
    }

    public function testExpectedLocaleWithoutFileReportsEveryKeyMissing(): void
    {
        $lines = TranslationParityChecker::format((new TranslationParityChecker())->checkParity(self::FIXTURES . '/ok', 'en', ['validators'], ['en', 'es', 'de']));

        self::assertSame(['de validators: missing key "required"'], $lines);
    }

    public function testMissingSourceAndYamlErrors(): void
    {
        $dir = sys_get_temp_dir() . '/tytb_parity_' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir . '/broken.en.yaml', "a: [unclosed\n");
        file_put_contents($dir . '/messages.en.yaml', "a: One\n");
        file_put_contents($dir . '/messages.es.yaml', "a: [unclosed\n");
        file_put_contents($dir . '/orphan.es.yaml', "a: Uno\n");
        file_put_contents($dir . '/solo.en.yaml', "a: Only English\n"); // no es file: skipped when locales are discovered

        $checker = new TranslationParityChecker();
        $parity  = $checker->checkParity($dir, 'en');
        $types   = array_map(static fn (array $v): string => $v['domain'] . '/' . $v['locale'] . '/' . $v['type'], $parity);
        self::assertSame(['broken/en/yaml_error', 'messages/es/yaml_error', 'orphan/en/source_missing'], $types);
        self::assertStringContainsString('orphan.en.yaml is missing or empty', TranslationParityChecker::format($parity)[2]);

        $untranslated = $checker->checkUntranslated($dir, 'en');
        $types        = array_map(static fn (array $v): string => $v['domain'] . '/' . $v['locale'] . '/' . $v['type'], $untranslated);
        self::assertSame(['broken/en/yaml_error', 'messages/es/yaml_error', 'orphan/en/source_missing'], $types);
    }

    public function testUntranslatedValues(): void
    {
        $checker = new TranslationParityChecker();
        $lines   = TranslationParityChecker::format($checker->checkUntranslated(self::FIXTURES . '/untranslated', 'en'));

        self::assertSame([
            'de messages: untranslated key "name"',
            'es messages: untranslated key "name"',
            'es messages: untranslated key "sentence"',
        ], $lines);

        self::assertSame([], $checker->checkUntranslated(
            self::FIXTURES . '/untranslated',
            'en',
            ['messages'],
            ['de', 'es'],
            ['*' => ['messages:name'], 'es' => ['messages:sentence']],
        ));

        $all = TranslationParityChecker::format($checker->checkUntranslated(self::FIXTURES . '/untranslated', 'en', null, ['es'], [], false));
        self::assertSame([
            'es messages: untranslated key "cmd"',
            'es messages: untranslated key "icu"',
            'es messages: untranslated key "label"',
            'es messages: untranslated key "name"',
            'es messages: untranslated key "sentence"',
        ], $all);
    }
}
