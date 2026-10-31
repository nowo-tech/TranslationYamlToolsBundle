<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Tests\Unit\Service;

use InvalidArgumentException;
use Nowo\TranslationYamlToolsBundle\Service\YamlCatalogueTextEditor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

#[CoversClass(YamlCatalogueTextEditor::class)]
final class YamlCatalogueTextEditorTest extends TestCase
{
    private const NESTED = <<<'YAML'
        # Navigation
        nav:
          home: 'Home'
          # Settings entry
          settings: 'Settings'
        user:
          email:
            blank: "Required"
        YAML;

    private YamlCatalogueTextEditor $editor;

    protected function setUp(): void
    {
        $this->editor = new YamlCatalogueTextEditor();
    }

    public function testAddsInAlphabeticalPositionAndKeepsComments(): void
    {
        $out = $this->editor->set(self::NESTED, 'nav.inbox', "It's new", false);

        self::assertSame(<<<'YAML'
            # Navigation
            nav:
              home: 'Home'
              inbox: 'It''s new'
              # Settings entry
              settings: 'Settings'
            user:
              email:
                blank: "Required"
            YAML, $out);
    }

    public function testAppendsAtTheEndOfAnUnsortedBlock(): void
    {
        $text = "nav:\n  zeta: 'Z'\n  alpha: 'A'\n";
        $out  = $this->editor->set($text, 'nav.beta', 'B', false);

        self::assertSame("nav:\n  zeta: 'Z'\n  alpha: 'A'\n  beta: 'B'\n", $out);
    }

    public function testCreatesMissingParentsWithTheFileIndent(): void
    {
        $out = $this->editor->set(self::NESTED, 'zone.page.title', 'Title', false);

        self::assertStringEndsWith("zone:\n  page:\n    title: 'Title'", $out);
        self::assertSame('Title', Yaml::parse($out)['zone']['page']['title']);
    }

    public function testUsesTheFallbackIndentWhenTheFileHasNoIndentedLine(): void
    {
        self::assertSame("a: 'x'\nb:\n   c: 'y'\n", $this->editor->set("a: 'x'\n", 'b.c', 'y', false, 3));
        self::assertSame("b:\n    c: 'y'\n", $this->editor->set('', 'b.c', 'y', false));
    }

    public function testKeepsTheFlatDotKeyStyle(): void
    {
        $text = "app.body: 'World'\napp.title: 'Hello'\n";
        $out  = $this->editor->set($text, 'app.footer', 'Foot', false);

        self::assertSame("app.body: 'World'\napp.footer: 'Foot'\napp.title: 'Hello'\n", $out);
        self::assertSame("app.body: 'World'\napp.title: 'Bye'\n", $this->editor->set($text, 'app.title', 'Bye', true));
    }

    public function testMatchesQuotedKeys(): void
    {
        $text = "'nav':\n  \"home\": 'Home'\n  'it''s': 'x'\n";

        self::assertSame("'nav':\n  \"home\": 'Start'\n  'it''s': 'x'\n", $this->editor->set($text, 'nav.home', 'Start', true));
        self::assertSame("'nav':\n  \"home\": 'Home'\n  'it''s': 'y'\n", $this->editor->set($text, "nav.it's", 'y', true));
        self::assertSame("'nav':\n  'a b': 'z'\n  \"home\": 'Home'\n  'it''s': 'x'\n", $this->editor->set($text, 'nav.a b', 'z', false));
    }

    public function testRefusesAnExistingKeyWithoutReplace(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('already exists');
        $this->editor->set(self::NESTED, 'nav.home', 'x', false);
    }

    public function testReplacesAValueIncludingABlockScalar(): void
    {
        $text = "intro: |\n  line one\n  line two\nnext: 'n'\n";
        $out  = $this->editor->set($text, 'intro', "a\nb", true);

        self::assertSame("intro: \"a\\nb\"\nnext: 'n'\n", $out);
        self::assertSame("a\nb", Yaml::parse($out)['intro']);
    }

    public function testRefusesToReplaceAGroup(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('is a group of keys');
        $this->editor->set(self::NESTED, 'user.email', 'x', true);
    }

    public function testRefusesAChildOfAValue(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"nav.home" is a value');
        $this->editor->set(self::NESTED, 'nav.home.title', 'x', false);
    }

    public function testRemovesAValue(): void
    {
        $out = $this->editor->remove(self::NESTED, 'nav.home');

        self::assertSame("# Navigation\nnav:\n  # Settings entry\n  settings: 'Settings'\nuser:\n  email:\n    blank: \"Required\"", $out);
    }

    public function testRemovesAGroupAndEmptyParents(): void
    {
        self::assertSame("# Navigation\nnav:\n  home: 'Home'\n  # Settings entry\n  settings: 'Settings'", $this->editor->remove(self::NESTED, 'user.email.blank'));
        self::assertSame("# Navigation\nuser:\n  email:\n    blank: \"Required\"", $this->editor->remove(self::NESTED, 'nav'));
        self::assertSame("a:\n  b: 'x'\n\nc: 'y'\n", $this->editor->remove("a:\n  b: 'x'\n  d: 'z'\n\nc: 'y'\n", 'a.d'));
    }

    public function testRemoveRefusesAnUnknownKey(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('does not exist');
        $this->editor->remove(self::NESTED, 'nav.unknown');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidKeys(): iterable
    {
        yield 'empty' => [''];
        yield 'leading dot' => ['.a'];
        yield 'double dot' => ['a..b'];
        yield 'trailing dot' => ['a.'];
    }

    #[DataProvider('invalidKeys')]
    public function testRejectsInvalidKeys(string $key): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->editor->set(self::NESTED, $key, 'x', false);
    }

    public function testFormatsScalars(): void
    {
        self::assertSame("'a''b'", $this->editor->formatScalar("a'b"));
        self::assertSame('"a\tb"', $this->editor->formatScalar("a\tb"));
        self::assertSame('true', $this->editor->formatScalar(true));
        self::assertSame('3', $this->editor->formatScalar(3));
        self::assertSame('null', $this->editor->formatScalar(null));
    }
}
