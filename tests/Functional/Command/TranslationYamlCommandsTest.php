<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Tests\Functional\Command;

use InvalidArgumentException;
use Nowo\TranslationYamlToolsBundle\Command\AbstractTranslationYamlCommand;
use Nowo\TranslationYamlToolsBundle\Command\TranslationYamlFillMissingCommand;
use Nowo\TranslationYamlToolsBundle\Command\TranslationYamlFlattenCommand;
use Nowo\TranslationYamlToolsBundle\Command\TranslationYamlSortCommand;
use Nowo\TranslationYamlToolsBundle\Command\TranslationYamlTreeCommand;
use Nowo\TranslationYamlToolsBundle\Service\DotKeyTreeAnalyzer;
use Nowo\TranslationYamlToolsBundle\Service\FrameworkTranslationPathsResolver;
use Nowo\TranslationYamlToolsBundle\Service\TranslationDefaultLocaleResolver;
use Nowo\TranslationYamlToolsBundle\Service\TranslationYamlCatalog;
use Nowo\TranslationYamlToolsBundle\Service\TranslationYamlFileHandler;
use Nowo\TranslationYamlToolsBundle\Service\YamlArraySorter;
use Nowo\TranslationYamlToolsBundle\Tests\Fixtures\StubMachineTranslator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\HelperSet;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Yaml\Yaml;

use function is_array;

#[CoversClass(AbstractTranslationYamlCommand::class)]
#[CoversClass(TranslationYamlTreeCommand::class)]
#[CoversClass(TranslationYamlSortCommand::class)]
#[CoversClass(TranslationYamlFillMissingCommand::class)]
#[CoversClass(TranslationYamlFlattenCommand::class)]
final class TranslationYamlCommandsTest extends TestCase
{
    /**
     * @param array<string, string> $filesUnderTranslations
     *
     * @return array{
     *     paths: FrameworkTranslationPathsResolver,
     *     catalog: TranslationYamlCatalog,
     *     bag: ParameterBag
     * }
     */
    private function createDeps(string $project, array $filesUnderTranslations = []): array
    {
        $translations = $project . '/translations';
        if (!is_dir($translations)) {
            mkdir($translations, 0777, true);
        }
        foreach ($filesUnderTranslations as $name => $content) {
            file_put_contents($translations . '/' . $name, $content);
        }

        $kernel = $this->createMock(KernelInterface::class);
        $kernel->method('getProjectDir')->willReturn($project);

        $bag = new ParameterBag([
            'kernel.project_dir'        => $project,
            'translator.default_path'   => $translations,
            'translator.default_locale' => 'en',
        ]);
        $paths   = new FrameworkTranslationPathsResolver($kernel, $bag);
        $catalog = new TranslationYamlCatalog($paths);

        return ['paths' => $paths, 'catalog' => $catalog, 'bag' => $bag];
    }

    /**
     * @param array{paths: FrameworkTranslationPathsResolver, catalog: TranslationYamlCatalog, bag: ParameterBag} $deps
     */
    private function treeCommand(array $deps, int $indent = 4, string $leafPrefixSuffix = 'index'): TranslationYamlTreeCommand
    {
        return new TranslationYamlTreeCommand(
            $deps['catalog'],
            $deps['paths'],
            new DotKeyTreeAnalyzer(),
            new TranslationYamlFileHandler(),
            $indent,
            $leafPrefixSuffix,
        );
    }

    /**
     * @param array{paths: FrameworkTranslationPathsResolver, catalog: TranslationYamlCatalog, bag: ParameterBag} $deps
     */
    private function sortCommand(array $deps, int $indent = 4): TranslationYamlSortCommand
    {
        return new TranslationYamlSortCommand(
            $deps['catalog'],
            $deps['paths'],
            new YamlArraySorter(),
            new DotKeyTreeAnalyzer(),
            new TranslationYamlFileHandler(),
            $indent,
        );
    }

    /**
     * @param array{paths: FrameworkTranslationPathsResolver, catalog: TranslationYamlCatalog, bag: ParameterBag} $deps
     */
    private function flattenCommand(array $deps, int $indent = 4): TranslationYamlFlattenCommand
    {
        return new TranslationYamlFlattenCommand(
            $deps['catalog'],
            $deps['paths'],
            new DotKeyTreeAnalyzer(),
            new TranslationYamlFileHandler(),
            $indent,
        );
    }

    /**
     * @param array{paths: FrameworkTranslationPathsResolver, catalog: TranslationYamlCatalog, bag: ParameterBag} $deps
     */
    private function fillCommand(
        array $deps,
        StubMachineTranslator $translator,
        ?string $bundleLocale = null,
        string $backend = 'google',
        int $indent = 4,
        bool $machineTranslatorPerLocaleEnabled = false,
    ): TranslationYamlFillMissingCommand {
        return new TranslationYamlFillMissingCommand(
            $deps['catalog'],
            $deps['paths'],
            new TranslationYamlFileHandler(),
            new TranslationDefaultLocaleResolver($deps['bag']),
            new DotKeyTreeAnalyzer(),
            new YamlArraySorter(),
            $translator,
            $bundleLocale,
            $indent,
            $backend,
            $machineTranslatorPerLocaleEnabled,
        );
    }

    private function bind(Command $command): void
    {
        $command->setHelperSet(new HelperSet([new QuestionHelper()]));
    }

    public function testTreeCommandDryRun(): void
    {
        $project = sys_get_temp_dir() . '/tyt_cmd_tree_' . uniqid();
        $deps    = $this->createDeps($project, [
            'messages.en.yaml' => Yaml::dump(['app.title' => 'Hi', 'app.body' => 'There'], 2, 4),
        ]);
        $cmd = $this->treeCommand($deps);
        $this->bind($cmd);
        $tester = new CommandTester($cmd);
        $exit   = $tester->execute([
            '--domain'  => 'messages',
            '--locale'  => 'en',
            '--dry-run' => true,
        ]);
        self::assertSame(0, $exit);
        self::assertStringContainsString('Dry-run', $tester->getDisplay());
    }

    public function testTreeCommandWritesNestedYaml(): void
    {
        $project = sys_get_temp_dir() . '/tyt_cmd_tree_w_' . uniqid();
        $deps    = $this->createDeps($project, [
            'messages.en.yaml' => Yaml::dump(['app.title' => 'Hi', 'app.body' => 'There'], 2, 4),
        ]);
        $path = $project . '/translations/messages.en.yaml';
        $cmd  = $this->treeCommand($deps);
        $this->bind($cmd);
        $tester = new CommandTester($cmd);
        $exit   = $tester->execute(['--domain' => 'messages', '--locale' => 'en']);
        self::assertSame(0, $exit);
        $loaded = Yaml::parseFile($path);
        self::assertIsArray($loaded);
        self::assertSame('Hi', $loaded['app']['title'] ?? null);
    }

    public function testTreeCommandFailsOnStructuralConflict(): void
    {
        $project = sys_get_temp_dir() . '/tyt_cmd_tree_c_' . uniqid();
        $deps    = $this->createDeps($project, [
            'messages.en.yaml' => Yaml::dump(['a' => 'leaf', 'a.b' => 'nested'], 2, 4),
        ]);
        $cmd = $this->treeCommand($deps);
        $this->bind($cmd);
        $tester = new CommandTester($cmd);
        $exit   = $tester->execute(['--domain' => 'messages', '--locale' => 'en', '--dry-run' => true]);
        self::assertSame(1, $exit);
        self::assertStringContainsString('Cannot convert to tree', $tester->getDisplay());
    }

    public function testTreeCommandFixLeafPrefixResolvesConflictAndWritesNestedYaml(): void
    {
        $project = sys_get_temp_dir() . '/tyt_cmd_tree_fix_' . uniqid();
        $deps    = $this->createDeps($project, [
            'messages.en.yaml' => Yaml::dump(['a' => 'leaf', 'a.b' => 'nested'], 2, 4),
        ]);
        $path = $project . '/translations/messages.en.yaml';
        $cmd  = $this->treeCommand($deps, 4, 'idx');
        $this->bind($cmd);
        $tester = new CommandTester($cmd);
        $exit   = $tester->execute([
            '--domain'             => 'messages',
            '--locale'             => 'en',
            '--fix-leaf-prefix'    => true,
            '--leaf-prefix-suffix' => 'idx',
        ]);
        self::assertSame(0, $exit);
        self::assertStringContainsString('Renamed leaf key "a" → "a.idx"', $tester->getDisplay());
        $loaded = Yaml::parseFile($path);
        self::assertIsArray($loaded);
        self::assertSame('leaf', $loaded['a']['idx'] ?? null);
        self::assertSame('nested', $loaded['a']['b'] ?? null);
    }

    public function testTreeCommandFailsWhenFileMissing(): void
    {
        $project = sys_get_temp_dir() . '/tyt_cmd_tree_m_' . uniqid();
        $deps    = $this->createDeps($project, [
            'messages.en.yaml' => "x: y\n",
        ]);
        $catalog = $this->createMock(TranslationYamlCatalog::class);
        $catalog->method('listDomains')->willReturn(['messages']);
        $catalog->method('listLocalesForDomain')->with('messages')->willReturn(['en']);
        $catalog->method('resolveFileForDomainLocale')->with('messages', 'en')->willReturn(null);
        $cmd = new TranslationYamlTreeCommand(
            $catalog,
            $deps['paths'],
            new DotKeyTreeAnalyzer(),
            new TranslationYamlFileHandler(),
            4,
            'index',
        );
        $this->bind($cmd);
        $tester = new CommandTester($cmd);
        $exit   = $tester->execute(['--domain' => 'messages', '--locale' => 'en']);
        self::assertSame(1, $exit);
        self::assertStringContainsString('No file found', $tester->getDisplay());
    }

    public function testTreeCommandFailsWhenNoTranslationFilesExist(): void
    {
        $project = sys_get_temp_dir() . '/tyt_cmd_tree_e_' . uniqid();
        $deps    = $this->createDeps($project, []);
        $cmd     = $this->treeCommand($deps);
        $this->bind($cmd);
        $tester = new CommandTester($cmd);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No translation YAML files found');
        $tester->execute(['--domain' => 'messages', '--locale' => 'en']);
    }

    public function testTreeCommandFailsOnUnknownDomain(): void
    {
        $project = sys_get_temp_dir() . '/tyt_cmd_tree_u_' . uniqid();
        $deps    = $this->createDeps($project, ['messages.en.yaml' => "a: b\n"]);
        $cmd     = $this->treeCommand($deps);
        $this->bind($cmd);
        $tester = new CommandTester($cmd);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown domain');
        $tester->execute(['--domain' => 'other', '--locale' => 'en']);
    }

    public function testTreeCommandInteractiveSelectsDomainAndLocale(): void
    {
        $project = sys_get_temp_dir() . '/tyt_cmd_tree_i_' . uniqid();
        $deps    = $this->createDeps($project, [
            'messages.en.yaml' => Yaml::dump(['a' => 'b'], 2, 4),
        ]);
        $cmd = $this->treeCommand($deps);
        $this->bind($cmd);
        $tester = new CommandTester($cmd);
        $tester->setInputs(['messages']);
        $exit = $tester->execute(['--dry-run' => true], ['interactive' => true]);
        self::assertSame(0, $exit);
    }

    public function testTreeCommandProcessesAllLocalesWhenLocaleOmitted(): void
    {
        $project = sys_get_temp_dir() . '/tyt_cmd_tree_all_' . uniqid();
        $deps    = $this->createDeps($project, [
            'messages.en.yaml' => Yaml::dump(['app.title' => 'Hi', 'app.body' => 'There'], 2, 4),
            'messages.fr.yaml' => Yaml::dump(['app.title' => 'Salut', 'app.body' => 'Là'], 2, 4),
        ]);
        $enPath = $project . '/translations/messages.en.yaml';
        $frPath = $project . '/translations/messages.fr.yaml';
        $cmd    = $this->treeCommand($deps);
        $this->bind($cmd);
        $tester = new CommandTester($cmd);
        $exit   = $tester->execute(['--domain' => 'messages'], ['interactive' => false]);
        self::assertSame(0, $exit);
        self::assertStringContainsString('converting all', $tester->getDisplay());
        self::assertSame('Hi', Yaml::parseFile($enPath)['app']['title'] ?? null);
        self::assertSame('Salut', Yaml::parseFile($frPath)['app']['title'] ?? null);
    }

    public function testAbstractFailsWhenNoLocalesForDomain(): void
    {
        $project = sys_get_temp_dir() . '/tyt_cmd_abs_' . uniqid();
        $deps    = $this->createDeps($project, ['messages.en.yaml' => "x: y\n"]);
        $catalog = $this->createMock(TranslationYamlCatalog::class);
        $catalog->method('listDomains')->willReturn(['messages']);
        $catalog->method('listLocalesForDomain')->with('messages')->willReturn([]);
        $cmd = new TranslationYamlTreeCommand(
            $catalog,
            $deps['paths'],
            new DotKeyTreeAnalyzer(),
            new TranslationYamlFileHandler(),
            4,
            'index',
        );
        $this->bind($cmd);
        $tester = new CommandTester($cmd);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No locale files found');
        $tester->execute(['--domain' => 'messages', '--locale' => 'en']);
    }

    public function testAbstractFailsNonInteractiveWithoutOptions(): void
    {
        $project = sys_get_temp_dir() . '/tyt_cmd_ni_' . uniqid();
        $deps    = $this->createDeps($project, ['messages.en.yaml' => "x: y\n"]);
        $cmd     = $this->sortCommand($deps);
        $this->bind($cmd);
        $tester = new CommandTester($cmd);
        $this->expectException(RuntimeException::class);
        $tester->execute([], ['interactive' => false]);
    }

    public function testSortCommandDryRun(): void
    {
        $project = sys_get_temp_dir() . '/tyt_cmd_sort_dr_' . uniqid();
        $deps    = $this->createDeps($project, [
            'messages.en.yaml' => Yaml::dump(['z' => 1, 'a' => 2], 2, 4),
        ]);
        $cmd = $this->sortCommand($deps);
        $this->bind($cmd);
        $tester = new CommandTester($cmd);
        $exit   = $tester->execute([
            '--domain'  => 'messages',
            '--locale'  => 'en',
            '--dry-run' => true,
        ]);
        self::assertSame(0, $exit);
        self::assertStringContainsString('Dry-run', $tester->getDisplay());
    }

    public function testSortCommandProcessesAllLocalesWhenLocaleOmitted(): void
    {
        $project = sys_get_temp_dir() . '/tyt_cmd_sort_all_' . uniqid();
        $deps    = $this->createDeps($project, [
            'messages.en.yaml' => Yaml::dump(['z' => 1, 'a' => 2], 2, 4),
            'messages.fr.yaml' => Yaml::dump(['m' => 1, 'b' => 2], 2, 4),
        ]);
        $enPath = $project . '/translations/messages.en.yaml';
        $frPath = $project . '/translations/messages.fr.yaml';
        $cmd    = $this->sortCommand($deps);
        $this->bind($cmd);
        $tester = new CommandTester($cmd);
        $exit   = $tester->execute(['--domain' => 'messages'], ['interactive' => false]);
        self::assertSame(0, $exit);
        self::assertStringContainsString('sorting all', $tester->getDisplay());
        self::assertSame(['a', 'z'], array_keys(Yaml::parseFile($enPath)));
        self::assertSame(['b', 'm'], array_keys(Yaml::parseFile($frPath)));
    }

    public function testSortCommandUnknownLocale(): void
    {
        $project = sys_get_temp_dir() . '/tyt_cmd_sort_loc_' . uniqid();
        $deps    = $this->createDeps($project, [
            'messages.en.yaml' => "x: y\n",
        ]);
        $cmd = $this->sortCommand($deps);
        $this->bind($cmd);
        $tester = new CommandTester($cmd);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown locale');
        $tester->execute(['--domain' => 'messages', '--locale' => 'fr']);
    }

    public function testSortCommandFailsWhenCatalogReturnsNoFile(): void
    {
        $project = sys_get_temp_dir() . '/tyt_cmd_sort_nf_' . uniqid();
        $deps    = $this->createDeps($project, [
            'messages.en.yaml' => "x: y\n",
        ]);
        $catalog = $this->createMock(TranslationYamlCatalog::class);
        $catalog->method('listDomains')->willReturn(['messages']);
        $catalog->method('listLocalesForDomain')->with('messages')->willReturn(['en']);
        $catalog->method('resolveFileForDomainLocale')->with('messages', 'en')->willReturn(null);
        $cmd = new TranslationYamlSortCommand(
            $catalog,
            $deps['paths'],
            new YamlArraySorter(),
            new DotKeyTreeAnalyzer(),
            new TranslationYamlFileHandler(),
            4,
        );
        $this->bind($cmd);
        $tester = new CommandTester($cmd);
        $exit   = $tester->execute(['--domain' => 'messages', '--locale' => 'en']);
        self::assertSame(1, $exit);
        self::assertStringContainsString('No translation file found', $tester->getDisplay());
    }

    public function testSortCommandWritesSortedKeys(): void
    {
        $project = sys_get_temp_dir() . '/tyt_cmd_sort_' . uniqid();
        $deps    = $this->createDeps($project, [
            'messages.en.yaml' => Yaml::dump(['z' => 1, 'a' => 2], 2, 4),
        ]);
        $path = $project . '/translations/messages.en.yaml';
        $cmd  = $this->sortCommand($deps);
        $this->bind($cmd);
        $tester = new CommandTester($cmd);
        $exit   = $tester->execute(['--domain' => 'messages', '--locale' => 'en']);
        self::assertSame(0, $exit);
        $keys = array_keys(Yaml::parseFile($path));
        self::assertSame(['a', 'z'], $keys);
    }

    public function testSortCommandWritesInlineFlowYaml(): void
    {
        $project = sys_get_temp_dir() . '/tyt_cmd_sort_il_' . uniqid();
        $deps    = $this->createDeps($project, [
            'messages.en.yaml' => Yaml::dump(['z' => 1, 'a' => 2], 2, 4),
        ]);
        $path = $project . '/translations/messages.en.yaml';
        $cmd  = $this->sortCommand($deps);
        $this->bind($cmd);
        $tester = new CommandTester($cmd);
        $exit   = $tester->execute(['--domain' => 'messages', '--locale' => 'en', '--inline' => true]);
        self::assertSame(0, $exit);
        self::assertStringContainsString('{', (string) file_get_contents($path));
        self::assertStringContainsString('inline flow', $tester->getDisplay());
    }

    public function testFlattenCommandFailsWhenCatalogReturnsNoFile(): void
    {
        $project = sys_get_temp_dir() . '/tyt_cmd_flat_nf_' . uniqid();
        $deps    = $this->createDeps($project, [
            'messages.en.yaml' => "x: y\n",
        ]);
        $catalog = $this->createMock(TranslationYamlCatalog::class);
        $catalog->method('listDomains')->willReturn(['messages']);
        $catalog->method('listLocalesForDomain')->with('messages')->willReturn(['en']);
        $catalog->method('resolveFileForDomainLocale')->with('messages', 'en')->willReturn(null);
        $cmd = new TranslationYamlFlattenCommand(
            $catalog,
            $deps['paths'],
            new DotKeyTreeAnalyzer(),
            new TranslationYamlFileHandler(),
            4,
        );
        $this->bind($cmd);
        $tester = new CommandTester($cmd);
        $exit   = $tester->execute(['--domain' => 'messages', '--locale' => 'en']);
        self::assertSame(1, $exit);
        self::assertStringContainsString('No translation file found', $tester->getDisplay());
    }

    public function testFlattenCommandDryRun(): void
    {
        $project = sys_get_temp_dir() . '/tyt_cmd_flat_dr_' . uniqid();
        $deps    = $this->createDeps($project, [
            'messages.en.yaml' => Yaml::dump(['app' => ['title' => 'Hi']], 2, 4),
        ]);
        $cmd = $this->flattenCommand($deps);
        $this->bind($cmd);
        $tester = new CommandTester($cmd);
        $exit   = $tester->execute([
            '--domain'  => 'messages',
            '--locale'  => 'en',
            '--dry-run' => true,
        ]);
        self::assertSame(0, $exit);
        self::assertStringContainsString('Dry-run', $tester->getDisplay());
        self::assertStringContainsString('flat dot keys', $tester->getDisplay());
    }

    public function testFlattenCommandProcessesAllLocalesWhenLocaleOmitted(): void
    {
        $project = sys_get_temp_dir() . '/tyt_cmd_flat_all_' . uniqid();
        $deps    = $this->createDeps($project, [
            'messages.en.yaml' => Yaml::dump(['app' => ['z' => 1, 'a' => 2]], 2, 4),
            'messages.fr.yaml' => Yaml::dump(['app' => ['m' => 1, 'b' => 2]], 2, 4),
        ]);
        $enPath = $project . '/translations/messages.en.yaml';
        $frPath = $project . '/translations/messages.fr.yaml';
        $cmd    = $this->flattenCommand($deps);
        $this->bind($cmd);
        $tester = new CommandTester($cmd);
        $exit   = $tester->execute(['--domain' => 'messages'], ['interactive' => false]);
        self::assertSame(0, $exit);
        self::assertStringContainsString('flattening all', $tester->getDisplay());
        $enFlat = Yaml::parseFile($enPath);
        self::assertIsArray($enFlat);
        self::assertArrayHasKey('app.a', $enFlat);
        self::assertArrayHasKey('app.z', $enFlat);
        $frFlat = Yaml::parseFile($frPath);
        self::assertIsArray($frFlat);
        self::assertArrayHasKey('app.b', $frFlat);
        self::assertArrayHasKey('app.m', $frFlat);
    }

    public function testFlattenCommandWritesDotKeysAtRootFromNested(): void
    {
        $project = sys_get_temp_dir() . '/tyt_cmd_flat_w_' . uniqid();
        $deps    = $this->createDeps($project, [
            'messages.en.yaml' => Yaml::dump(['app' => ['title' => 'Hi', 'body' => 'There']], 2, 4),
        ]);
        $path = $project . '/translations/messages.en.yaml';
        $cmd  = $this->flattenCommand($deps);
        $this->bind($cmd);
        $tester = new CommandTester($cmd);
        $exit   = $tester->execute(['--domain' => 'messages', '--locale' => 'en']);
        self::assertSame(0, $exit);
        $loaded = Yaml::parseFile($path);
        self::assertIsArray($loaded);
        self::assertArrayHasKey('app.body', $loaded);
        self::assertArrayHasKey('app.title', $loaded);
        self::assertSame('Hi', $loaded['app.title']);
        self::assertFalse(isset($loaded['app']) && is_array($loaded['app']));
    }

    public function testFlattenCommandWritesInlineFlowYaml(): void
    {
        $project = sys_get_temp_dir() . '/tyt_cmd_flat_il_' . uniqid();
        $deps    = $this->createDeps($project, [
            'messages.en.yaml' => Yaml::dump(['z' => ['a' => 1]], 2, 4),
        ]);
        $path = $project . '/translations/messages.en.yaml';
        $cmd  = $this->flattenCommand($deps);
        $this->bind($cmd);
        $tester = new CommandTester($cmd);
        $exit   = $tester->execute(['--domain' => 'messages', '--locale' => 'en', '--inline' => true]);
        self::assertSame(0, $exit);
        self::assertStringContainsString('{', (string) file_get_contents($path));
        self::assertStringContainsString('inline flow', $tester->getDisplay());
    }

    public function testFillMissingNoMissingKeys(): void
    {
        $project = sys_get_temp_dir() . '/tyt_cmd_fm0_' . uniqid();
        $deps    = $this->createDeps($project, [
            'messages.en.yaml' => "k: v\n",
            'messages.es.yaml' => "k: v\n",
        ]);
        $cmd = $this->fillCommand($deps, new StubMachineTranslator());
        $this->bind($cmd);
        $tester = new CommandTester($cmd);
        $exit   = $tester->execute([
            '--domain'        => 'messages',
            '--target-locale' => 'es',
        ]);
        self::assertSame(0, $exit);
        self::assertStringContainsString('No missing keys', $tester->getDisplay());
    }

    public function testFillMissingDryRun(): void
    {
        $project = sys_get_temp_dir() . '/tyt_cmd_fm_dr_' . uniqid();
        $deps    = $this->createDeps($project, [
            'messages.en.yaml' => "new_key: hello\n",
            'messages.es.yaml' => "old: x\n",
        ]);
        $cmd = $this->fillCommand($deps, new StubMachineTranslator());
        $this->bind($cmd);
        $tester = new CommandTester($cmd);
        $exit   = $tester->execute([
            '--domain'        => 'messages',
            '--target-locale' => 'es',
            '--dry-run'       => true,
        ]);
        self::assertSame(0, $exit);
        self::assertStringContainsString('Dry-run', $tester->getDisplay());
        self::assertStringContainsString('new_key', $tester->getDisplay());
    }

    public function testFillMissingShowsPerLocaleMachineTranslatorHintWhenEnabled(): void
    {
        $project = sys_get_temp_dir() . '/tyt_cmd_fm_pl_' . uniqid();
        $deps    = $this->createDeps($project, [
            'messages.en.yaml' => "a: One\n",
            'messages.es.yaml' => "a: Uno\n",
        ]);
        $cmd = $this->fillCommand($deps, new StubMachineTranslator(), null, 'google', 4, true);
        $this->bind($cmd);
        $tester = new CommandTester($cmd);
        $exit   = $tester->execute([
            '--domain'        => 'messages',
            '--target-locale' => 'es',
            '--dry-run'       => true,
        ]);
        self::assertSame(0, $exit);
        self::assertStringContainsString('per-locale overrides', $tester->getDisplay());
        self::assertStringContainsString('machine_translator_by_locale', $tester->getDisplay());
    }

    public function testFillMissingTranslatesAndWrites(): void
    {
        $project = sys_get_temp_dir() . '/tyt_cmd_fm_w_' . uniqid();
        $deps    = $this->createDeps($project, [
            'messages.en.yaml' => "greet: Hello\nnum: 7\n",
            'messages.es.yaml' => "old: legacy\n",
        ]);
        $path = $project . '/translations/messages.es.yaml';
        $cmd  = $this->fillCommand($deps, new StubMachineTranslator('T:'));
        $this->bind($cmd);
        $tester = new CommandTester($cmd);
        $exit   = $tester->execute([
            '--domain'        => 'messages',
            '--target-locale' => 'es',
        ], ['decorated' => false]);
        self::assertSame(0, $exit);
        $data = Yaml::parseFile($path);
        self::assertSame('T:Hello', $data['greet']);
        self::assertSame(7, $data['num']);
    }

    public function testFillMissingCreatesTargetFileWhenMissing(): void
    {
        $project = sys_get_temp_dir() . '/tyt_cmd_fm_new_' . uniqid();
        $deps    = $this->createDeps($project, [
            'messages.en.yaml' => "only: source\n",
        ]);
        $cmd = $this->fillCommand($deps, new StubMachineTranslator());
        $this->bind($cmd);
        $tester = new CommandTester($cmd);
        $exit   = $tester->execute([
            '--domain'        => 'messages',
            '--target-locale' => 'de',
        ], ['decorated' => false]);
        self::assertSame(0, $exit);
        self::assertFileExists($project . '/translations/messages.de.yaml');
    }

    public function testFillMissingFailsWhenSourceEqualsTarget(): void
    {
        $project = sys_get_temp_dir() . '/tyt_cmd_fm_same_' . uniqid();
        $deps    = $this->createDeps($project, ['messages.en.yaml' => "a: b\n"]);
        $cmd     = $this->fillCommand($deps, new StubMachineTranslator());
        $this->bind($cmd);
        $tester = new CommandTester($cmd);
        $exit   = $tester->execute([
            '--domain'        => 'messages',
            '--target-locale' => 'en',
            '--source-locale' => 'en',
        ]);
        self::assertSame(1, $exit);
    }

    public function testFillMissingFailsWhenSourceFileMissing(): void
    {
        $project = sys_get_temp_dir() . '/tyt_cmd_fm_ns_' . uniqid();
        $deps    = $this->createDeps($project, ['messages.es.yaml' => "a: b\n"]);
        $cmd     = $this->fillCommand($deps, new StubMachineTranslator());
        $this->bind($cmd);
        $tester = new CommandTester($cmd);
        $exit   = $tester->execute([
            '--domain'        => 'messages',
            '--target-locale' => 'es',
            '--source-locale' => 'en',
        ]);
        self::assertSame(1, $exit);
    }

    public function testFillMissingFailsOnTranslatorError(): void
    {
        $project = sys_get_temp_dir() . '/tyt_cmd_fm_err_' . uniqid();
        $deps    = $this->createDeps($project, [
            'messages.en.yaml' => "one: a\ntwo: b\n",
            'messages.es.yaml' => "x: y\n",
        ]);
        $cmd = $this->fillCommand($deps, new StubMachineTranslator('T:', true));
        $this->bind($cmd);
        $tester = new CommandTester($cmd);
        $exit   = $tester->execute([
            '--domain'        => 'messages',
            '--target-locale' => 'es',
        ], ['decorated' => false]);
        self::assertSame(1, $exit);
        self::assertStringContainsString('Translation failed', $tester->getDisplay());
    }

    public function testFillMissingTreeModeConflict(): void
    {
        $project = sys_get_temp_dir() . '/tyt_cmd_fm_tr_' . uniqid();
        $deps    = $this->createDeps($project, [
            'messages.en.yaml' => Yaml::dump(['a' => 'x', 'a.b' => 'y'], 2, 4),
            'messages.es.yaml' => "keep: z\n",
        ]);
        $cmd = $this->fillCommand($deps, new StubMachineTranslator('T:'));
        $this->bind($cmd);
        $tester = new CommandTester($cmd);
        $exit   = $tester->execute([
            '--domain'        => 'messages',
            '--target-locale' => 'es',
            '--tree'          => true,
        ], ['decorated' => false]);
        self::assertSame(1, $exit);
        self::assertStringContainsString('Cannot write as tree', $tester->getDisplay());
    }

    public function testFillMissingWithTreeSuccess(): void
    {
        $project = sys_get_temp_dir() . '/tyt_cmd_fm_oktr_' . uniqid();
        $deps    = $this->createDeps($project, [
            'messages.en.yaml' => "x.y: first\n",
            'messages.es.yaml' => "old: 1\n",
        ]);
        $cmd = $this->fillCommand($deps, new StubMachineTranslator('T:'));
        $this->bind($cmd);
        $tester = new CommandTester($cmd);
        $exit   = $tester->execute([
            '--domain'        => 'messages',
            '--target-locale' => 'es',
            '--tree'          => true,
        ], ['decorated' => false]);
        self::assertSame(0, $exit);
        $parsed = Yaml::parseFile($project . '/translations/messages.es.yaml');
        self::assertSame('T:first', $parsed['x']['y']);
    }

    public function testFillMissingVerboseListsKeys(): void
    {
        $project = sys_get_temp_dir() . '/tyt_cmd_fm_v_' . uniqid();
        $deps    = $this->createDeps($project, [
            'messages.en.yaml' => "solo: text\n",
            'messages.es.yaml' => "x: y\n",
        ]);
        $cmd = $this->fillCommand($deps, new StubMachineTranslator());
        $this->bind($cmd);
        $tester = new CommandTester($cmd);
        $exit   = $tester->execute([
            '--domain'        => 'messages',
            '--target-locale' => 'es',
        ], [
            'decorated' => false,
            'verbosity' => OutputInterface::VERBOSITY_VERBOSE,
        ]);
        self::assertSame(0, $exit);
        self::assertStringContainsString('solo', $tester->getDisplay());
    }

    public function testFillMissingShowsProgressWhenDecorated(): void
    {
        $project = sys_get_temp_dir() . '/tyt_cmd_fm_pb_' . uniqid();
        $deps    = $this->createDeps($project, [
            'messages.en.yaml' => "a: 1\nb: 2\n",
            'messages.es.yaml' => "x: y\n",
        ]);
        $cmd = $this->fillCommand($deps, new StubMachineTranslator());
        $this->bind($cmd);
        $tester = new CommandTester($cmd);
        $exit   = $tester->execute([
            '--domain'        => 'messages',
            '--target-locale' => 'es',
        ], [
            'decorated' => true,
            'verbosity' => OutputInterface::VERBOSITY_NORMAL,
        ]);
        self::assertSame(0, $exit);
    }

    public function testFillMissingVerboseWithDecoratedUsesElseBranchWithoutProgressBar(): void
    {
        $project = sys_get_temp_dir() . '/tyt_cmd_fm_ev_' . uniqid();
        $deps    = $this->createDeps($project, [
            'messages.en.yaml' => "only: text\n",
            'messages.es.yaml' => "x: y\n",
        ]);
        $cmd = $this->fillCommand($deps, new StubMachineTranslator());
        $this->bind($cmd);
        $tester = new CommandTester($cmd);
        $exit   = $tester->execute([
            '--domain'        => 'messages',
            '--target-locale' => 'es',
        ], [
            'decorated' => true,
            'verbosity' => OutputInterface::VERBOSITY_VERBOSE,
        ]);
        self::assertSame(0, $exit);
    }

    public function testFillMissingCopiesNonStringMissingValues(): void
    {
        $project = sys_get_temp_dir() . '/tyt_cmd_fm_nonstr_' . uniqid();
        $deps    = $this->createDeps($project, [
            'messages.en.yaml' => Yaml::dump(['label' => 'Hi', 'count' => 99], 2, 4),
            'messages.es.yaml' => "old: x\n",
        ]);
        $cmd = $this->fillCommand($deps, new StubMachineTranslator());
        $this->bind($cmd);
        $tester = new CommandTester($cmd);
        $exit   = $tester->execute([
            '--domain'        => 'messages',
            '--target-locale' => 'es',
        ], ['decorated' => false]);
        self::assertSame(0, $exit);
        $data = Yaml::parseFile($project . '/translations/messages.es.yaml');
        self::assertSame(99, $data['count']);
        self::assertSame('T:Hi', $data['label']);
    }

    public function testGuessTargetPathFallsBackToYamlWhenSourceHasNoExtension(): void
    {
        $project = sys_get_temp_dir() . '/tyt_guess_ext_' . uniqid();
        mkdir($project . '/translations', 0777, true);
        $deps   = $this->createDeps($project, ['messages.en.yaml' => "k: v\n"]);
        $cmd    = $this->fillCommand($deps, new StubMachineTranslator());
        $method = new ReflectionMethod(TranslationYamlFillMissingCommand::class, 'guessTargetPathForNewFile');
        $out    = $method->invoke($cmd, 'messages', 'de', '/tmp/plainfile');
        self::assertStringEndsWith('messages.de.yaml', $out);
    }

    public function testFillMissingInteractiveSelectsDomainAndTarget(): void
    {
        $project = sys_get_temp_dir() . '/tyt_cmd_fm_i_' . uniqid();
        $deps    = $this->createDeps($project, [
            'messages.en.yaml' => "interactive: yes\n",
            'messages.de.yaml' => "old: x\n",
        ]);
        $cmd = $this->fillCommand($deps, new StubMachineTranslator());
        $this->bind($cmd);
        $tester = new CommandTester($cmd);
        $tester->setInputs(['messages', 'de']);
        $exit = $tester->execute([], ['interactive' => true, 'decorated' => false]);
        self::assertSame(0, $exit);
        $written = Yaml::parseFile($project . '/translations/messages.de.yaml');
        self::assertIsArray($written);
        self::assertArrayHasKey('interactive', $written);
    }

    public function testFillMissingNonInteractiveRequiresTargetLocale(): void
    {
        $project = sys_get_temp_dir() . '/tyt_cmd_fm_nt_' . uniqid();
        $deps    = $this->createDeps($project, ['messages.en.yaml' => "a: b\n"]);
        $cmd     = $this->fillCommand($deps, new StubMachineTranslator());
        $this->bind($cmd);
        $tester = new CommandTester($cmd);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('--target-locale');
        $tester->execute(['--domain' => 'messages'], ['interactive' => false]);
    }

    public function testFillMissingNonInteractiveRequiresDomain(): void
    {
        $project = sys_get_temp_dir() . '/tyt_cmd_fm_nd_' . uniqid();
        $deps    = $this->createDeps($project, ['messages.en.yaml' => "a: b\n"]);
        $cmd     = $this->fillCommand($deps, new StubMachineTranslator());
        $this->bind($cmd);
        $tester = new CommandTester($cmd);
        $this->expectException(RuntimeException::class);
        $tester->execute(['--target-locale' => 'es'], ['interactive' => false]);
    }

    public function testFillMissingUnknownDomain(): void
    {
        $project = sys_get_temp_dir() . '/tyt_cmd_fm_ud_' . uniqid();
        $deps    = $this->createDeps($project, ['messages.en.yaml' => "a: b\n"]);
        $cmd     = $this->fillCommand($deps, new StubMachineTranslator());
        $this->bind($cmd);
        $tester = new CommandTester($cmd);
        $this->expectException(InvalidArgumentException::class);
        $tester->execute([
            '--domain'        => 'ghost',
            '--target-locale' => 'es',
        ]);
    }

    public function testFillMissingRejectsUnsafeTargetLocale(): void
    {
        $project = sys_get_temp_dir() . '/tyt_cmd_fm_badloc_' . uniqid();
        $deps    = $this->createDeps($project, ['messages.en.yaml' => "a: b\n"]);
        $cmd     = $this->fillCommand($deps, new StubMachineTranslator());
        $this->bind($cmd);
        $tester = new CommandTester($cmd);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid target-locale');
        $tester->execute([
            '--domain'        => 'messages',
            '--target-locale' => '../evil',
        ]);
    }

    public function testFillMissingRespectsMaxRequestsPerRun(): void
    {
        $project = sys_get_temp_dir() . '/tyt_cmd_fm_maxreq_' . uniqid();
        $deps    = $this->createDeps($project, [
            'messages.en.yaml' => "a: one\nb: two\nc: three\n",
        ]);
        $cmd = new TranslationYamlFillMissingCommand(
            $deps['catalog'],
            $deps['paths'],
            new TranslationYamlFileHandler(),
            new TranslationDefaultLocaleResolver($deps['bag']),
            new DotKeyTreeAnalyzer(),
            new YamlArraySorter(),
            new StubMachineTranslator(),
            null,
            4,
            'google',
            false,
            2,
        );
        $this->bind($cmd);
        $tester = new CommandTester($cmd);
        $exit   = $tester->execute([
            '--domain'        => 'messages',
            '--target-locale' => 'es',
        ], ['decorated' => false]);
        self::assertSame(1, $exit);
        self::assertStringContainsString('machine_translation_max_requests_per_run', $tester->getDisplay());
    }

    public function testDomainsFoundLineShowsNone(): void
    {
        $project = sys_get_temp_dir() . '/tyt_cmd_none_' . uniqid();
        mkdir($project . '/translations', 0777, true);
        $kernel = $this->createMock(KernelInterface::class);
        $kernel->method('getProjectDir')->willReturn($project);
        $bag = new ParameterBag([
            'kernel.project_dir'      => $project,
            'translator.default_path' => $project . '/translations',
        ]);
        $paths   = new FrameworkTranslationPathsResolver($kernel, $bag);
        $catalog = new TranslationYamlCatalog($paths);
        $deps    = ['paths' => $paths, 'catalog' => $catalog, 'bag' => $bag];
        $cmd     = $this->treeCommand($deps);
        $this->bind($cmd);
        $tester = new CommandTester($cmd);
        $this->expectException(RuntimeException::class);
        $tester->execute(['--domain' => 'x', '--locale' => 'y']);
    }

    public function testGuessTargetPathUsesCwdWhenNoTranslationDirs(): void
    {
        $project = sys_get_temp_dir() . '/tyt_guess_' . uniqid();
        mkdir($project . '/translations', 0777, true);
        $deps  = $this->createDeps($project, ['messages.en.yaml' => "k: v\n"]);
        $paths = $this->createMock(FrameworkTranslationPathsResolver::class);
        $paths->method('resolveTranslationDirectories')->willReturn([]);
        $cmd = new TranslationYamlFillMissingCommand(
            $deps['catalog'],
            $paths,
            new TranslationYamlFileHandler(),
            new TranslationDefaultLocaleResolver($deps['bag']),
            new DotKeyTreeAnalyzer(),
            new YamlArraySorter(),
            new StubMachineTranslator(),
            null,
            4,
            'google',
            false,
        );
        $method      = new ReflectionMethod(TranslationYamlFillMissingCommand::class, 'guessTargetPathForNewFile');
        $previousCwd = getcwd();
        try {
            self::assertNotFalse(chdir($project));
            $out = $method->invoke($cmd, 'messages', 'de', $project . '/translations/messages.en.yaml');
            self::assertSame($project . '/translations/messages.de.yaml', $out);
        } finally {
            if ($previousCwd !== false) {
                chdir($previousCwd);
            }
        }
    }

    public function testFillMissingShowsNoneDomains(): void
    {
        $project = sys_get_temp_dir() . '/tyt_cmd_fm_none_' . uniqid();
        mkdir($project . '/translations', 0777, true);
        $kernel = $this->createMock(KernelInterface::class);
        $kernel->method('getProjectDir')->willReturn($project);
        $bag = new ParameterBag([
            'kernel.project_dir'        => $project,
            'translator.default_path'   => $project . '/translations',
            'translator.default_locale' => 'en',
        ]);
        $paths   = new FrameworkTranslationPathsResolver($kernel, $bag);
        $catalog = new TranslationYamlCatalog($paths);
        $deps    = ['paths' => $paths, 'catalog' => $catalog, 'bag' => $bag];
        $cmd     = $this->fillCommand($deps, new StubMachineTranslator());
        $this->bind($cmd);
        $tester = new CommandTester($cmd);
        $this->expectException(InvalidArgumentException::class);
        $tester->execute(['--domain' => 'messages', '--target-locale' => 'es']);
    }

    public function testResolveDomainAndLocaleThrowsWhenNoLocalesExist(): void
    {
        $project = sys_get_temp_dir() . '/tyt_cmd_resolve_' . uniqid();
        $deps    = $this->createDeps($project, []);
        $catalog = $this->createMock(TranslationYamlCatalog::class);
        $catalog->method('listDomains')->willReturn(['messages']);
        $catalog->method('listLocalesForDomain')->with('messages')->willReturn([]);

        $cmd = new TranslationYamlTreeCommand(
            $catalog,
            $deps['paths'],
            new DotKeyTreeAnalyzer(),
            new TranslationYamlFileHandler(),
            4,
            'index',
        );

        $method = new ReflectionMethod(AbstractTranslationYamlCommand::class, 'resolveDomainAndLocale');
        $input  = $this->createMock(InputInterface::class);
        $output = $this->createMock(OutputInterface::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No locale files found for domain "messages".');

        $method->invoke($cmd, $input, $output, 'messages', 'en');
    }

    public function testResolveDomainAndLocaleThrowsForUnknownLocale(): void
    {
        $project = sys_get_temp_dir() . '/tyt_cmd_resolve_u_' . uniqid();
        $deps    = $this->createDeps($project, ['messages.en.yaml' => "a: b\n"]);
        $cmd     = $this->treeCommand($deps);

        $method = new ReflectionMethod(AbstractTranslationYamlCommand::class, 'resolveDomainAndLocale');
        $input  = $this->createMock(InputInterface::class);
        $output = $this->createMock(OutputInterface::class);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown locale "fr" for domain "messages".');

        $method->invoke($cmd, $input, $output, 'messages', 'fr');
    }

    public function testResolveDomainAndLocaleSelectsLocaleInteractively(): void
    {
        $project = sys_get_temp_dir() . '/tyt_cmd_resolve_i_' . uniqid();
        $deps    = $this->createDeps($project, [
            'messages.en.yaml' => "a: b\n",
            'messages.fr.yaml' => "a: c\n",
        ]);
        $cmd = $this->treeCommand($deps);
        $this->bind($cmd);

        $input = new ArrayInput([], $cmd->getDefinition());
        $input->setInteractive(true);
        $output = new BufferedOutput();
        $stream = fopen('php://memory', 'r+');
        self::assertNotFalse($stream);
        fwrite($stream, "fr\n");
        rewind($stream);
        $input->setStream($stream);

        $method = new ReflectionMethod(AbstractTranslationYamlCommand::class, 'resolveDomainAndLocale');
        $result = $method->invoke($cmd, $input, $output, 'messages', null);

        self::assertSame(['domain' => 'messages', 'locale' => 'fr'], $result);
    }

    public function testTreeCommandReportsDisambiguationFailure(): void
    {
        $project = sys_get_temp_dir() . '/tyt_cmd_tree_fail_' . uniqid();
        $deps    = $this->createDeps($project, [
            'messages.en.yaml' => Yaml::dump(['a' => 'leaf', 'a.index' => 'exists', 'a.b' => 'nested'], 2, 4),
        ]);
        $cmd = $this->treeCommand($deps);
        $this->bind($cmd);
        $tester = new CommandTester($cmd);
        $exit   = $tester->execute([
            '--domain'          => 'messages',
            '--locale'          => 'en',
            '--fix-leaf-prefix' => true,
        ]);

        self::assertSame(1, $exit);
        self::assertStringContainsString('Cannot disambiguate leaf/prefix keys.', $tester->getDisplay());
        self::assertStringContainsString('already exists', $tester->getDisplay());
    }
}
