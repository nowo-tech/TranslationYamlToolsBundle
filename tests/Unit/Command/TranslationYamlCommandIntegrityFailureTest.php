<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Tests\Unit\Command;

use Nowo\TranslationYamlToolsBundle\Command\TranslationYamlFillMissingCommand;
use Nowo\TranslationYamlToolsBundle\Command\TranslationYamlFlattenCommand;
use Nowo\TranslationYamlToolsBundle\Command\TranslationYamlSortCommand;
use Nowo\TranslationYamlToolsBundle\Command\TranslationYamlTreeCommand;
use Nowo\TranslationYamlToolsBundle\MachineTranslation\MachineTranslatorInterface;
use Nowo\TranslationYamlToolsBundle\Service\DotKeyTreeAnalyzer;
use Nowo\TranslationYamlToolsBundle\Service\FrameworkTranslationPathsResolver;
use Nowo\TranslationYamlToolsBundle\Service\TranslationDefaultLocaleResolver;
use Nowo\TranslationYamlToolsBundle\Service\TranslationYamlCatalog;
use Nowo\TranslationYamlToolsBundle\Service\TranslationYamlFileHandler;
use Nowo\TranslationYamlToolsBundle\Service\YamlArraySorter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

use function dirname;

#[CoversClass(TranslationYamlFlattenCommand::class)]
#[CoversClass(TranslationYamlSortCommand::class)]
#[CoversClass(TranslationYamlTreeCommand::class)]
#[CoversClass(TranslationYamlFillMissingCommand::class)]
final class TranslationYamlCommandIntegrityFailureTest extends TestCase
{
    /**
     * @return array{0: MockObject&TranslationYamlCatalog, 1: FrameworkTranslationPathsResolver&MockObject}
     */
    private function baseCatalogAndPaths(string $enPath, ?string $frPath = null): array
    {
        $catalog = $this->createMock(TranslationYamlCatalog::class);
        $catalog->method('listDomains')->willReturn(['messages']);
        $catalog->method('listLocalesForDomain')->willReturn(['en', 'fr']);
        $map = [['messages', 'en', $enPath]];
        if ($frPath !== null) {
            $map[] = ['messages', 'fr', $frPath];
        }
        $catalog->method('resolveFileForDomainLocale')->willReturnMap($map);

        $paths = $this->createMock(FrameworkTranslationPathsResolver::class);
        $paths->method('resolveTranslationDirectories')->willReturn([dirname($enPath)]);
        $paths->method('describeResolutionSources')->willReturn([]);

        return [$catalog, $paths];
    }

    public function testFlattenFailsWhenLeafIntegrityCheckFails(): void
    {
        $enPath = sys_get_temp_dir() . '/tyt_flatten_' . uniqid('', true) . '.yaml';
        file_put_contents($enPath, "a: 1\n");

        [$catalog, $paths] = $this->baseCatalogAndPaths($enPath);

        $fileHandler = new TranslationYamlFileHandler();

        $analyzer = $this->createMock(DotKeyTreeAnalyzer::class);
        $analyzer->method('flatten')->willReturn(['k' => 'v']);
        $analyzer->method('verifyFlattenedLeavesPreserved')->willReturn('Integrity check failed (test).');

        $command = new TranslationYamlFlattenCommand(
            $catalog,
            $paths,
            $analyzer,
            $fileHandler,
            4,
        );

        $input = new ArrayInput(['--domain' => 'messages', '--locale' => 'en']);
        $input->setInteractive(false);

        $exit = $command->run(
            $input,
            $output = new BufferedOutput(),
        );

        self::assertSame(1, $exit);
        self::assertStringContainsString('Leaf key integrity check failed', $output->fetch());
        @unlink($enPath);
    }

    public function testSortFailsWhenLeafIntegrityCheckFails(): void
    {
        $enPath = sys_get_temp_dir() . '/tyt_sort_' . uniqid('', true) . '.yaml';
        file_put_contents($enPath, "z: 1\n");

        [$catalog, $paths] = $this->baseCatalogAndPaths($enPath);

        $fileHandler = new TranslationYamlFileHandler();

        $sorter = $this->createMock(YamlArraySorter::class);
        $sorter->method('sortAssociativeRecursive')->willReturn(['z' => 1]);

        $analyzer = $this->createMock(DotKeyTreeAnalyzer::class);
        $analyzer->method('flatten')->willReturn(['z' => 1]);
        $analyzer->method('countFlattenedLeaves')->willReturn(1);
        $analyzer->method('verifyFlattenedLeavesPreserved')->willReturn('Sort integrity failed (test).');

        $command = new TranslationYamlSortCommand(
            $catalog,
            $paths,
            $sorter,
            $analyzer,
            $fileHandler,
            4,
        );

        $input = new ArrayInput(['--domain' => 'messages', '--locale' => 'en']);
        $input->setInteractive(false);

        $exit = $command->run(
            $input,
            $output = new BufferedOutput(),
        );

        self::assertSame(1, $exit);
        self::assertStringContainsString('Leaf key integrity check failed', $output->fetch());
        @unlink($enPath);
    }

    public function testTreeFailsWhenLeafIntegrityCheckFails(): void
    {
        $enPath = sys_get_temp_dir() . '/tyt_tree_' . uniqid('', true) . '.yaml';
        file_put_contents($enPath, "'a.b': x\n");

        [$catalog, $paths] = $this->baseCatalogAndPaths($enPath);

        $fileHandler = new TranslationYamlFileHandler();

        $analyzer = $this->createMock(DotKeyTreeAnalyzer::class);
        $analyzer->method('flatten')->willReturn(['a.b' => 'x']);
        $analyzer->method('treeConversionConflict')->willReturn(null);
        $analyzer->method('unflatten')->willReturn(['a' => ['b' => 'x']]);
        $analyzer->method('countFlattenedLeaves')->willReturn(1);
        $analyzer->method('verifyFlattenedLeavesPreserved')->willReturn('Tree integrity failed (test).');

        $command = new TranslationYamlTreeCommand(
            $catalog,
            $paths,
            $analyzer,
            $fileHandler,
            4,
            'index',
        );

        $input = new ArrayInput(['--domain' => 'messages', '--locale' => 'en']);
        $input->setInteractive(false);

        $exit = $command->run(
            $input,
            $output = new BufferedOutput(),
        );

        self::assertSame(1, $exit);
        self::assertStringContainsString('Leaf key integrity check failed', $output->fetch());
        @unlink($enPath);
    }

    public function testTreeFailsWhenConflictRemainsAfterDisambiguation(): void
    {
        $enPath = sys_get_temp_dir() . '/tyt_tree_after_' . uniqid('', true) . '.yaml';
        file_put_contents($enPath, "a: x\n");

        [$catalog, $paths] = $this->baseCatalogAndPaths($enPath);

        $fileHandler = new TranslationYamlFileHandler();

        $analyzer = $this->createMock(DotKeyTreeAnalyzer::class);
        $analyzer->method('flatten')->willReturn(['a' => 'x', 'a.b' => 'y']);
        $analyzer->method('treeConversionConflict')->willReturnOnConsecutiveCalls(
            'leaf/prefix conflict',
            'still conflicted after rename',
        );
        $analyzer->method('disambiguateLeafPrefixConflicts')->willReturn([
            'flat'    => ['a.index' => 'x', 'a.b' => 'y'],
            'renames' => [['from' => 'a', 'to' => 'a.index']],
        ]);

        $command = new TranslationYamlTreeCommand(
            $catalog,
            $paths,
            $analyzer,
            $fileHandler,
            4,
            'index',
        );

        $input = new ArrayInput([
            '--domain'          => 'messages',
            '--locale'          => 'en',
            '--fix-leaf-prefix' => true,
        ]);
        $input->setInteractive(false);

        $exit = $command->run(
            $input,
            $output = new BufferedOutput(),
        );

        self::assertSame(1, $exit);
        $display = $output->fetch();
        self::assertStringContainsString('Cannot convert to tree after disambiguation', $display);
        self::assertStringContainsString('still conflicted after rename', $display);
        @unlink($enPath);
    }

    public function testFillMissingFailsWhenLeafIntegrityCheckFails(): void
    {
        $enPath = sys_get_temp_dir() . '/tyt_fill_en_' . uniqid('', true) . '.yaml';
        $frPath = sys_get_temp_dir() . '/tyt_fill_fr_' . uniqid('', true) . '.yaml';
        file_put_contents($enPath, "hello: Hi\n");
        file_put_contents($frPath, "{ }\n");

        [$catalog, $paths] = $this->baseCatalogAndPaths($enPath, $frPath);

        $fileHandler = new TranslationYamlFileHandler();

        $defaultLocale = $this->createMock(TranslationDefaultLocaleResolver::class);
        $defaultLocale->method('resolve')->willReturn('en');

        $analyzer = $this->createMock(DotKeyTreeAnalyzer::class);
        $analyzer->method('flatten')->willReturnMap([
            [['hello' => 'Hi'], ['hello' => 'Hi']],
            [[], []],
        ]);
        $analyzer->method('countFlattenedLeaves')->willReturn(1);
        $analyzer->method('verifyFlattenedLeavesPreserved')->willReturn('Fill integrity failed (test).');

        $sorter = $this->createMock(YamlArraySorter::class);
        $sorter->method('sortAssociativeRecursive')->willReturn(['hello' => 'Salut']);

        $mt = $this->createMock(MachineTranslatorInterface::class);
        $mt->method('translate')->willReturn('Salut');

        $command = new TranslationYamlFillMissingCommand(
            $catalog,
            $paths,
            $fileHandler,
            $defaultLocale,
            $analyzer,
            $sorter,
            $mt,
            null,
            4,
            'google',
            false,
        );

        $input = new ArrayInput([
            '--domain'        => 'messages',
            '--target-locale' => 'fr',
        ]);
        $input->setInteractive(false);

        $exit = $command->run(
            $input,
            $output = new BufferedOutput(),
        );

        self::assertSame(1, $exit);
        self::assertStringContainsString('Leaf key integrity check failed; file not written', $output->fetch());
        @unlink($enPath);
        @unlink($frPath);
    }
}
