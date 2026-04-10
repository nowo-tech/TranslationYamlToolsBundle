<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Tests\Functional\Command;

use Nowo\TranslationYamlToolsBundle\Command\TranslationYamlAuditCommand;
use Nowo\TranslationYamlToolsBundle\Service\DotKeyTreeAnalyzer;
use Nowo\TranslationYamlToolsBundle\Service\FrameworkTranslationPathsResolver;
use Nowo\TranslationYamlToolsBundle\Service\TranslationDefaultLocaleResolver;
use Nowo\TranslationYamlToolsBundle\Service\TranslationYamlAuditor;
use Nowo\TranslationYamlToolsBundle\Service\TranslationYamlCatalog;
use Nowo\TranslationYamlToolsBundle\Service\TranslationYamlFileHandler;
use Nowo\TranslationYamlToolsBundle\Service\YamlArraySorter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\HelperSet;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\HttpKernel\KernelInterface;

#[CoversClass(TranslationYamlAuditCommand::class)]
final class TranslationYamlAuditCommandTest extends TestCase
{
    /**
     * @return array{catalog: TranslationYamlCatalog, paths: FrameworkTranslationPathsResolver, bag: ParameterBag}
     */
    private function createDeps(string $project, array $filesUnderTranslations): array
    {
        $translations = $project . '/translations';
        mkdir($translations, 0777, true);
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

        return ['catalog' => $catalog, 'paths' => $paths, 'bag' => $bag];
    }

    private function auditCommand(array $deps): TranslationYamlAuditCommand
    {
        return new TranslationYamlAuditCommand(
            $deps['catalog'],
            $deps['paths'],
            new TranslationYamlAuditor(
                $deps['catalog'],
                new TranslationYamlFileHandler(),
                new DotKeyTreeAnalyzer(),
                new YamlArraySorter(),
            ),
            new TranslationDefaultLocaleResolver($deps['bag']),
            null,
        );
    }

    private function bind(TranslationYamlAuditCommand $cmd): void
    {
        $cmd->setHelperSet(new HelperSet([new QuestionHelper()]));
    }

    public function testAuditSuccessWhenDomainFullyOk(): void
    {
        $project = sys_get_temp_dir() . '/tyt_audit_ok_' . uniqid();
        $deps    = $this->createDeps($project, [
            'messages.en.yaml' => "a: One\nb: Two\n",
            'messages.es.yaml' => "a: Uno\nb: Dos\n",
        ]);
        $cmd = $this->auditCommand($deps);
        $this->bind($cmd);
        $tester = new CommandTester($cmd);
        $exit   = $tester->execute(['--source-locale' => 'en']);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('messages', $tester->getDisplay());
        self::assertStringContainsString('tree-safe', $tester->getDisplay());
    }

    public function testAuditFailureWhenTreeConflict(): void
    {
        $project = sys_get_temp_dir() . '/tyt_audit_tree_' . uniqid();
        $deps    = $this->createDeps($project, [
            'bad.en.yaml' => "a: leaf\na.b: x\n",
        ]);
        $cmd = $this->auditCommand($deps);
        $this->bind($cmd);
        $tester = new CommandTester($cmd);
        $exit   = $tester->execute(['--domain' => 'bad', '--source-locale' => 'en']);

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString('leaf_and_prefix', $tester->getDisplay());
    }

    public function testAuditUnknownDomain(): void
    {
        $project = sys_get_temp_dir() . '/tyt_audit_ud_' . uniqid();
        $deps    = $this->createDeps($project, ['messages.en.yaml' => "a: b\n"]);
        $cmd     = $this->auditCommand($deps);
        $this->bind($cmd);
        $tester = new CommandTester($cmd);
        $exit   = $tester->execute(['--domain' => 'ghost']);

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString('Unknown domain', $tester->getDisplay());
    }

    public function testAuditWithNoDomainsPrintsCommentAndSucceeds(): void
    {
        $project      = sys_get_temp_dir() . '/tyt_audit_empty_' . uniqid();
        $translations = $project . '/translations';
        mkdir($translations, 0777, true);
        $kernel = $this->createMock(KernelInterface::class);
        $kernel->method('getProjectDir')->willReturn($project);
        $bag = new ParameterBag([
            'kernel.project_dir'        => $project,
            'translator.default_path'   => $translations,
            'translator.default_locale' => 'en',
        ]);
        $paths   = new FrameworkTranslationPathsResolver($kernel, $bag);
        $catalog = new TranslationYamlCatalog($paths);
        $deps    = ['catalog' => $catalog, 'paths' => $paths, 'bag' => $bag];
        $cmd     = $this->auditCommand($deps);
        $this->bind($cmd);
        $tester = new CommandTester($cmd);
        $exit   = $tester->execute(['--source-locale' => 'en']);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('No domains to audit', $tester->getDisplay());
    }

    public function testAuditOutputIncludesSourceErrorWhenSourceFileMissing(): void
    {
        $project = sys_get_temp_dir() . '/tyt_audit_srcerr_' . uniqid();
        $deps    = $this->createDeps($project, ['messages.es.yaml' => "a: x\n"]);
        $cmd     = $this->auditCommand($deps);
        $this->bind($cmd);
        $tester = new CommandTester($cmd);
        $exit   = $tester->execute(['--source-locale' => 'en']);

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString('Source:', $tester->getDisplay());
        self::assertStringContainsString('No translation file for domain', $tester->getDisplay());
    }

    public function testAuditOutputIncludesYamlErrorBullet(): void
    {
        $project = sys_get_temp_dir() . '/tyt_audit_yamlerr_' . uniqid();
        $deps    = $this->createDeps($project, [
            'messages.en.yaml' => "a: ok\n",
            'messages.es.yaml' => "a: [\n",
        ]);
        $cmd = $this->auditCommand($deps);
        $this->bind($cmd);
        $tester = new CommandTester($cmd);
        $exit   = $tester->execute(['--source-locale' => 'en']);

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString('YAML error:', $tester->getDisplay());
    }

    public function testAuditOutputIncludesUnsortedKeysBullet(): void
    {
        $project = sys_get_temp_dir() . '/tyt_audit_sort_' . uniqid();
        $deps    = $this->createDeps($project, [
            'messages.en.yaml' => "z: last\na: first\n",
        ]);
        $cmd = $this->auditCommand($deps);
        $this->bind($cmd);
        $tester = new CommandTester($cmd);
        $exit   = $tester->execute(['--source-locale' => 'en']);

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString('not alphabetically sorted', $tester->getDisplay());
    }

    public function testAuditOutputIncludesMissingKeysBullet(): void
    {
        $project = sys_get_temp_dir() . '/tyt_audit_miss_' . uniqid();
        $deps    = $this->createDeps($project, [
            'messages.en.yaml' => "a: One\nb: Two\n",
            'messages.es.yaml' => "a: Uno\n",
        ]);
        $cmd = $this->auditCommand($deps);
        $this->bind($cmd);
        $tester = new CommandTester($cmd);
        $exit   = $tester->execute(['--source-locale' => 'en']);

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString('missing key', $tester->getDisplay());
    }

    public function testAuditSkipsLocaleLinesWhenSourceErrorAndSecondaryLocaleClean(): void
    {
        $project = sys_get_temp_dir() . '/tyt_audit_skiprow_' . uniqid();
        $deps    = $this->createDeps($project, ['messages.es.yaml' => "a: x\n"]);
        $cmd     = $this->auditCommand($deps);
        $this->bind($cmd);
        $tester = new CommandTester($cmd);
        $exit   = $tester->execute(['--source-locale' => 'en']);

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString('Source:', $tester->getDisplay());
        self::assertStringNotContainsString('YAML error:', $tester->getDisplay());
    }
}
