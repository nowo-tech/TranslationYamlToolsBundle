<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Tests\Functional\Command;

use Nowo\TranslationYamlToolsBundle\Command\TranslationYamlKeyCommand;
use Nowo\TranslationYamlToolsBundle\Service\DotKeyTreeAnalyzer;
use Nowo\TranslationYamlToolsBundle\Service\FrameworkTranslationPathsResolver;
use Nowo\TranslationYamlToolsBundle\Service\TranslationDefaultLocaleResolver;
use Nowo\TranslationYamlToolsBundle\Service\TranslationYamlCatalog;
use Nowo\TranslationYamlToolsBundle\Service\TranslationYamlFileHandler;
use Nowo\TranslationYamlToolsBundle\Service\YamlCatalogueTextEditor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Yaml\Yaml;

#[CoversClass(TranslationYamlKeyCommand::class)]
final class TranslationYamlKeyCommandTest extends TestCase
{
    private string $project;

    private string $translations;

    protected function setUp(): void
    {
        $this->project      = sys_get_temp_dir() . '/tyt_key_' . uniqid();
        $this->translations = $this->project . '/translations';
        mkdir($this->translations, 0777, true);
        $this->write('messages.en.yaml', "# Main\nnav:\n    home: 'Home'\n    settings: 'Settings'\nsection:\n    a: 'A'\n    b: 'B'\n");
        $this->write('messages.es.yaml', "nav:\n    home: 'Inicio'\n    settings: 'Ajustes'\nsection:\n    a: 'A es'\n    b: 'B es'\n");
    }

    protected function tearDown(): void
    {
        foreach ([...(glob($this->translations . '/*') ?: []), ...(glob($this->project . '/other/*') ?: [])] as $file) {
            unlink($file);
        }
        @rmdir($this->project . '/other');
        rmdir($this->translations);
        rmdir($this->project);
    }

    /**
     * @param list<string> $configuredLocales
     * @param list<string> $enabledLocales
     */
    private function tester(array $configuredLocales = [], array $enabledLocales = []): CommandTester
    {
        return new CommandTester($this->command($configuredLocales, $enabledLocales));
    }

    /**
     * @param list<string> $configuredLocales
     * @param list<string> $enabledLocales
     */
    private function command(array $configuredLocales = [], array $enabledLocales = []): TranslationYamlKeyCommand
    {
        $kernel = $this->createMock(KernelInterface::class);
        $kernel->method('getProjectDir')->willReturn($this->project);
        $bag = new ParameterBag([
            'kernel.project_dir'        => $this->project,
            'translator.default_path'   => $this->translations,
            'translator.default_locale' => 'en',
        ]);
        $paths = new FrameworkTranslationPathsResolver($kernel, $bag);

        return new TranslationYamlKeyCommand(
            new TranslationYamlCatalog($paths),
            $paths,
            new TranslationYamlFileHandler(),
            new DotKeyTreeAnalyzer(),
            new YamlCatalogueTextEditor(),
            new TranslationDefaultLocaleResolver($bag),
            $configuredLocales,
            $enabledLocales,
        );
    }

    private function write(string $name, string $content): void
    {
        file_put_contents($this->translations . '/' . $name, $content);
    }

    private function read(string $name): string
    {
        return (string) file_get_contents($this->translations . '/' . $name);
    }

    public function testGetPrintsTheValueInEveryLocale(): void
    {
        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute(['action' => 'get', 'domain' => 'messages', 'key' => 'nav.home']));
        self::assertSame("en: \"Home\"\nes: \"Inicio\"\n", $tester->getDisplay());

        $tester->execute(['action' => 'get', 'domain' => 'messages', 'key' => 'section']);
        self::assertStringContainsString('en: (group of 2 key(s))', $tester->getDisplay());
        $tester->execute(['action' => 'get', 'domain' => 'messages', 'key' => 'nav.nope']);
        self::assertStringContainsString('es: (missing)', $tester->getDisplay());
    }

    public function testListShowsKeysUnderAPrefixAndFlagsMissingLocales(): void
    {
        $this->write('messages.es.yaml', "nav:\n    home: 'Inicio'\n");
        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute(['action' => 'list', 'domain' => 'messages', 'key' => 'nav']));
        $display = $tester->getDisplay();
        self::assertStringContainsString('nav.home = "Home"', $display);
        self::assertStringContainsString('nav.settings = "Settings" [missing: es]', $display);
        self::assertStringNotContainsString('section.a', $display);
        self::assertStringContainsString('2 key(s) in messages.en under nav', $display);

        $tester->execute(['action' => 'list', 'domain' => 'messages']);
        self::assertStringContainsString('4 key(s) in messages.en', $tester->getDisplay());
    }

    public function testAddWritesEveryLocaleKeepingTheFile(): void
    {
        $tester = $this->tester();
        $exit   = $tester->execute(['action' => 'add', 'domain' => 'messages', 'key' => 'nav.inbox', 'value' => '{"en":"Inbox","es":"Bandeja"}']);

        self::assertSame(Command::SUCCESS, $exit, $tester->getDisplay());
        self::assertStringContainsString('OK added nav.inbox in 2 locale(s)', $tester->getDisplay());
        self::assertSame("# Main\nnav:\n    home: 'Home'\n    inbox: 'Inbox'\n    settings: 'Settings'\nsection:\n    a: 'A'\n    b: 'B'\n", $this->read('messages.en.yaml'));
        self::assertSame('Bandeja', Yaml::parse($this->read('messages.es.yaml'))['nav']['inbox']);
        self::assertSame([], glob($this->translations . '/*.tmp') ?: [], 'staged files are swapped in');
    }

    public function testWritesKeepTheFileMode(): void
    {
        chmod($this->translations . '/messages.en.yaml', 0o640);
        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute(['action' => 'remove', 'domain' => 'messages', 'key' => 'nav.home']));
        clearstatcache();
        self::assertSame(0o640, fileperms($this->translations . '/messages.en.yaml') & 0o7777);
    }

    public function testAddRefusesAnExistingKeyUnlessReplace(): void
    {
        $tester = $this->tester();

        self::assertSame(Command::FAILURE, $tester->execute(['action' => 'add', 'domain' => 'messages', 'key' => 'nav.home', 'value' => '{"en":"H","es":"I"}']));
        self::assertStringContainsString('en: Key "nav.home" already exists', $tester->getDisplay());

        self::assertSame(Command::SUCCESS, $tester->execute(['action' => 'add', 'domain' => 'messages', 'key' => 'nav.home', 'value' => '{"en":"Start","es":"Comienzo"}', '--replace' => true]));
        self::assertStringContainsString('OK set nav.home', $tester->getDisplay());
        self::assertStringContainsString("home: 'Comienzo'", $this->read('messages.es.yaml'));
    }

    public function testAddIsAllOrNothing(): void
    {
        // es already has the key as a value parent: en would accept, es refuses, so neither file changes.
        $this->write('messages.es.yaml', "nav:\n    home: 'Inicio'\n    settings: 'Ajustes'\n    new: 'x'\nsection:\n    a: 'A es'\n    b: 'B es'\n");
        $before = $this->read('messages.en.yaml');
        $tester = $this->tester();

        self::assertSame(Command::FAILURE, $tester->execute(['action' => 'add', 'domain' => 'messages', 'key' => 'nav.new.title', 'value' => '{"en":"T","es":"T"}']));
        self::assertStringContainsString('es: Cannot add "nav.new.title"', $tester->getDisplay());
        self::assertSame($before, $this->read('messages.en.yaml'));
    }

    public function testAddValidatesTheValues(): void
    {
        $tester = $this->tester();

        self::assertSame(Command::FAILURE, $tester->execute(['action' => 'add', 'domain' => 'messages', 'key' => 'nav.x']));
        self::assertStringContainsString('needs the values', $tester->getDisplay());
        self::assertSame(Command::FAILURE, $tester->execute(['action' => 'add', 'domain' => 'messages', 'key' => 'nav.x', 'value' => '["a"]']));
        self::assertStringContainsString('must be a JSON object', $tester->getDisplay());
        self::assertSame(Command::FAILURE, $tester->execute(['action' => 'add', 'domain' => 'messages', 'key' => 'nav.x', 'value' => '{"en":"X"}']));
        self::assertStringContainsString('Missing locales: es', $tester->getDisplay());
        self::assertSame(Command::FAILURE, $tester->execute(['action' => 'add', 'domain' => 'messages', 'key' => 'nav.x', 'value' => '{"en":"X","es":"X","fr":"X"}']));
        self::assertStringContainsString('Unknown locales: fr', $tester->getDisplay());
        self::assertSame(Command::FAILURE, $tester->execute(['action' => 'add', 'domain' => 'messages', 'key' => 'nav.x', 'value' => '{"en":"X","es":3}']));
        self::assertStringContainsString('es: the value must be a string', $tester->getDisplay());
    }

    public function testAddReadsAJsonFileOrStdin(): void
    {
        $json = $this->project . '/values.json';
        file_put_contents($json, '{"en":"From file","es":"Del fichero"}');
        $tester = $this->tester();
        self::assertSame(Command::SUCCESS, $tester->execute(['action' => 'add', 'domain' => 'messages', 'key' => 'file.key', 'value' => $json]));
        unlink($json);

        $tester->setInputs(['{"en":"Line 1\\nLine 2","es":"L\\u00ednea"}']);
        self::assertSame(Command::SUCCESS, $tester->execute(['action' => 'add', 'domain' => 'messages', 'key' => 'stdin.key', 'value' => '-']));

        $en = Yaml::parse($this->read('messages.en.yaml'));
        self::assertSame('From file', $en['file']['key']);
        self::assertSame("Line 1\nLine 2", $en['stdin']['key']);
        self::assertSame('Línea', Yaml::parse($this->read('messages.es.yaml'))['stdin']['key']);
    }

    public function testDryRunPrintsTheChangeAndWritesNothing(): void
    {
        $before = $this->read('messages.en.yaml');
        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute(['action' => 'add', 'domain' => 'messages', 'key' => 'nav.inbox', 'value' => '{"en":"Inbox","es":"Bandeja"}', '--dry-run' => true]));
        $display = $tester->getDisplay();
        self::assertStringContainsString('@@ line 4', $display);
        self::assertStringContainsString("+     inbox: 'Inbox'", $display);
        self::assertStringContainsString('Dry-run: would have added nav.inbox in 2 locale(s)', $display);
        self::assertSame($before, $this->read('messages.en.yaml'));

        $tester->execute(['action' => 'remove', 'domain' => 'messages', 'key' => 'nav.home', '--dry-run' => true]);
        self::assertStringContainsString("-     home: 'Home'", $tester->getDisplay());
    }

    public function testRenameMovesAValueAndAGroup(): void
    {
        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute(['action' => 'rename', 'domain' => 'messages', 'key' => 'nav.settings', 'value' => 'nav.preferences']));
        self::assertStringContainsString('OK renamed nav.settings to nav.preferences in 2 locale(s)', $tester->getDisplay());
        self::assertSame("# Main\nnav:\n    home: 'Home'\n    preferences: 'Settings'\nsection:\n    a: 'A'\n    b: 'B'\n", $this->read('messages.en.yaml'));

        self::assertSame(Command::SUCCESS, $tester->execute(['action' => 'rename', 'domain' => 'messages', 'key' => 'section', 'value' => 'area.part']));
        $es = Yaml::parse($this->read('messages.es.yaml'));
        self::assertSame(['a' => 'A es', 'b' => 'B es'], $es['area']['part']);
        self::assertArrayNotHasKey('section', $es);
    }

    public function testRenameRefusesUnsafeTargets(): void
    {
        $tester = $this->tester();

        self::assertSame(Command::FAILURE, $tester->execute(['action' => 'rename', 'domain' => 'messages', 'key' => 'nav.home']));
        self::assertStringContainsString('needs the new key', $tester->getDisplay());
        self::assertSame(Command::FAILURE, $tester->execute(['action' => 'rename', 'domain' => 'messages', 'key' => 'nav', 'value' => 'nav.sub']));
        self::assertStringContainsString('must be outside the old one', $tester->getDisplay());
        self::assertSame(Command::FAILURE, $tester->execute(['action' => 'rename', 'domain' => 'messages', 'key' => 'nav.home', 'value' => 'nav.settings']));
        self::assertStringContainsString('Key "nav.settings" already exists', $tester->getDisplay());
        self::assertSame(Command::FAILURE, $tester->execute(['action' => 'rename', 'domain' => 'messages', 'key' => 'nav.home', 'value' => 'nav.settings.x']));
        self::assertStringContainsString('Key "nav.settings.x" already exists', $tester->getDisplay());

        $this->write('messages.es.yaml', "nav:\n    settings: 'Ajustes'\nsection:\n    a: 'A es'\n    b: 'B es'\n");
        self::assertSame(Command::FAILURE, $tester->execute(['action' => 'rename', 'domain' => 'messages', 'key' => 'nav.home', 'value' => 'nav.start']));
        self::assertStringContainsString('es: Key "nav.home" does not exist (rename needs it in every locale)', $tester->getDisplay());
    }

    public function testRenameRefusesLists(): void
    {
        $this->write('messages.en.yaml', "items: [a, b]\n");
        $this->write('messages.es.yaml', "items: [c, d]\n");
        $tester = $this->tester();

        self::assertSame(Command::FAILURE, $tester->execute(['action' => 'rename', 'domain' => 'messages', 'key' => 'items', 'value' => 'things']));
        self::assertStringContainsString('holds a list', $tester->getDisplay());
    }

    public function testRemoveDeletesInEveryLocaleAndReportsAbsentOnes(): void
    {
        $this->write('messages.es.yaml', "nav:\n    home: 'Inicio'\n");
        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute(['action' => 'remove', 'domain' => 'messages', 'key' => 'section']));
        self::assertStringContainsString('Key "section" was already absent in: es.', $tester->getDisplay());
        self::assertStringContainsString('OK removed section in 1 locale(s)', $tester->getDisplay());
        self::assertSame("# Main\nnav:\n    home: 'Home'\n    settings: 'Settings'\n", $this->read('messages.en.yaml'));

        self::assertSame(Command::FAILURE, $tester->execute(['action' => 'remove', 'domain' => 'messages', 'key' => 'section']));
        self::assertStringContainsString('does not exist in any locale', $tester->getDisplay());
    }

    public function testEditsThatDoNotReadBackAreRefused(): void
    {
        // A flow mapping is not walked: the new key would land outside it, which the read-back catches.
        $this->write('messages.en.yaml', "nav: { home: 'Home' }\n");
        $this->write('messages.es.yaml', "nav: { home: 'Inicio' }\n");
        $tester = $this->tester();

        self::assertSame(Command::FAILURE, $tester->execute(['action' => 'add', 'domain' => 'messages', 'key' => 'nav.x', 'value' => '{"en":"X","es":"X"}']));
        self::assertStringContainsString('is a value, not a group of keys', $tester->getDisplay());

        // A merge key: the new value also appears under "page", so the result is not the expected key set.
        $merged = "defaults: &d\n    title: 'T'\npage:\n    <<: *d\n";
        $this->write('messages.en.yaml', $merged);
        $this->write('messages.es.yaml', $merged);
        self::assertSame(Command::FAILURE, $tester->execute(['action' => 'add', 'domain' => 'messages', 'key' => 'defaults.sub', 'value' => '{"en":"S","es":"S"}']));
        self::assertStringContainsString('en: the edit does not read back', $tester->getDisplay());
        self::assertSame($merged, $this->read('messages.en.yaml'));
    }

    public function testEditsThatBreakTheFileAreRefused(): void
    {
        // Removing the anchor leaves a dangling alias: the result does not parse.
        $this->write('messages.en.yaml', "base: &b 'x'\nref: *b\n");
        $this->write('messages.es.yaml', "base: &b 'y'\nref: *b\n");
        $tester = $this->tester();

        self::assertSame(Command::FAILURE, $tester->execute(['action' => 'remove', 'domain' => 'messages', 'key' => 'base']));
        self::assertStringContainsString('en: the edit does not parse', $tester->getDisplay());
    }

    public function testUnparsableResultIsRefused(): void
    {
        $this->write('messages.en.yaml', "nav:\n    home: 'Home'\n\tbad: 'x'\n");
        $this->write('messages.es.yaml', "nav:\n    home: 'Inicio'\n");
        $tester = $this->tester();

        self::assertSame(Command::FAILURE, $tester->execute(['action' => 'get', 'domain' => 'messages', 'key' => 'nav.home']));
        self::assertStringContainsString('Invalid YAML', $tester->getDisplay());
    }

    public function testMissingReportsDifferencesAndPasses(): void
    {
        $this->write('validators.en.yaml', "a: 'A'\n");
        $this->write('validators.es.yaml', "a: 'A'\n");
        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute(['action' => 'missing']));
        self::assertStringContainsString('OK all locales have the same keys', $tester->getDisplay());

        $this->write('messages.es.yaml', "nav:\n    home: 'Inicio'\nextra: 'x'\n");
        self::assertSame(Command::FAILURE, $tester->execute(['action' => 'missing', 'domain' => 'messages']));
        $display = $tester->getDisplay();
        self::assertStringContainsString('messages.es: missing nav.settings', $display);
        self::assertStringContainsString('messages.es: not in en extra', $display);
        self::assertStringContainsString('FAIL 4 difference(s)', $display);
    }

    public function testMissingCountsAnAbsentLocaleFile(): void
    {
        $tester = $this->tester([], ['en', 'es', 'fr']);

        self::assertSame(Command::FAILURE, $tester->execute(['action' => 'missing', 'domain' => 'messages']));
        self::assertStringContainsString('messages.fr: missing nav.home', $tester->getDisplay());
        self::assertSame(Command::FAILURE, $tester->execute(['action' => 'get', 'domain' => 'messages', 'key' => 'nav.home']));
        self::assertStringContainsString('No catalogue file for domain "messages" and locale "fr"', $tester->getDisplay());
    }

    public function testLocalesComeFromOptionThenConfigThenEnabledLocales(): void
    {
        $this->write('messages.fr.yaml', "nav:\n    home: 'Accueil'\n");

        $tester = $this->tester(['es']);
        $tester->execute(['action' => 'get', 'domain' => 'messages', 'key' => 'nav.home']);
        self::assertSame("es: \"Inicio\"\n", $tester->getDisplay());

        $tester = $this->tester([], ['fr', 'en']);
        $tester->execute(['action' => 'get', 'domain' => 'messages', 'key' => 'nav.home']);
        self::assertSame("fr: \"Accueil\"\nen: \"Home\"\n", $tester->getDisplay());

        $tester->execute(['action' => 'get', 'domain' => 'messages', 'key' => 'nav.home', '--locales' => 'es, fr,']);
        self::assertSame("es: \"Inicio\"\nfr: \"Accueil\"\n", $tester->getDisplay());

        // Reference locale for list: the default locale when listed, else the first one.
        $tester->execute(['action' => 'list', 'domain' => 'messages', '--locales' => 'fr,es']);
        self::assertStringContainsString('in messages.fr', $tester->getDisplay());

        self::assertSame(Command::FAILURE, $tester->execute(['action' => 'get', 'domain' => 'messages', 'key' => 'nav.home', '--locales' => 'e/s']));
        self::assertStringContainsString('Invalid locale "e/s"', $tester->getDisplay());
    }

    public function testDirOptionReadsAnotherDirectory(): void
    {
        $other = $this->project . '/other';
        mkdir($other);
        file_put_contents($other . '/Shop.en.yml', "cart: 'Cart'\n");
        file_put_contents($other . '/Shop.de.yaml', "cart: 'Warenkorb'\n");
        $tester = $this->tester();

        $tester->execute(['action' => 'get', 'domain' => 'Shop', 'key' => 'cart', '--dir' => $other . '/']);
        self::assertSame("de: \"Warenkorb\"\nen: \"Cart\"\n", $tester->getDisplay());
        self::assertSame(Command::SUCCESS, $tester->execute(['action' => 'missing', '--dir' => $other]));
        self::assertSame(Command::FAILURE, $tester->execute(['action' => 'get', 'domain' => 'Nope', 'key' => 'cart', '--dir' => $other]));
        self::assertStringContainsString('No locale files found for domain "Nope"', $tester->getDisplay());
        self::assertSame(Command::FAILURE, $tester->execute(['action' => 'get', 'domain' => 'Shop', 'key' => 'cart', '--dir' => $other . '/none']));
        self::assertStringContainsString('Directory not found', $tester->getDisplay());
    }

    public function testArgumentErrors(): void
    {
        $tester = $this->tester();

        self::assertSame(Command::FAILURE, $tester->execute(['action' => 'explode']));
        self::assertStringContainsString('Unknown action "explode"', $tester->getDisplay());
        self::assertSame(Command::FAILURE, $tester->execute(['action' => 'get']));
        self::assertStringContainsString('The get action needs a domain', $tester->getDisplay());
        self::assertSame(Command::FAILURE, $tester->execute(['action' => 'get', 'domain' => 'messages']));
        self::assertStringContainsString('The get action needs a key', $tester->getDisplay());
        self::assertSame(Command::FAILURE, $tester->execute(['action' => 'get', 'domain' => '../etc', 'key' => 'a']));
        self::assertStringContainsString('Invalid domain "../etc"', $tester->getDisplay());
        self::assertSame(Command::FAILURE, $tester->execute(['action' => 'missing', 'domain' => 'a/b']));
        self::assertStringContainsString('Invalid domain', $tester->getDisplay());
    }
}
