<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Tests\Unit\Service;

use Nowo\TranslationYamlToolsBundle\Service\DotKeyTreeAnalyzer;
use Nowo\TranslationYamlToolsBundle\Service\FrameworkTranslationPathsResolver;
use Nowo\TranslationYamlToolsBundle\Service\TranslationYamlAuditor;
use Nowo\TranslationYamlToolsBundle\Service\TranslationYamlCatalog;
use Nowo\TranslationYamlToolsBundle\Service\TranslationYamlFileHandler;
use Nowo\TranslationYamlToolsBundle\Service\YamlArraySorter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\HttpKernel\KernelInterface;

#[CoversClass(TranslationYamlAuditor::class)]
final class TranslationYamlAuditorTest extends TestCase
{
    public function testAllOkWhenSingleLocaleSortedAndTreeSafe(): void
    {
        $project = sys_get_temp_dir() . '/tytb_audit_' . bin2hex(random_bytes(4));
        mkdir($project . '/translations', 0777, true);
        file_put_contents($project . '/translations/messages.en.yaml', "a: One\nb: Two\n");

        $auditor = $this->createAuditor($project);
        $reports = $auditor->audit('en');

        self::assertCount(1, $reports);
        self::assertSame('messages', $reports[0]['domain']);
        self::assertTrue($reports[0]['all_ok']);
        self::assertNull($reports[0]['source_error']);
    }

    public function testReportsMissingKeysForSecondaryLocale(): void
    {
        $project = sys_get_temp_dir() . '/tytb_audit_' . bin2hex(random_bytes(4));
        mkdir($project . '/translations', 0777, true);
        file_put_contents($project . '/translations/messages.en.yaml', "a: One\nb: Two\n");
        file_put_contents($project . '/translations/messages.es.yaml', "a: Uno\n");

        $auditor = $this->createAuditor($project);
        $reports = $auditor->audit('en');

        self::assertFalse($reports[0]['all_ok']);
        $es = $reports[0]['locales'][1];
        self::assertSame('es', $es['locale']);
        self::assertSame(1, $es['missing_vs_source']);
    }

    public function testSourceMissingYieldsError(): void
    {
        $project = sys_get_temp_dir() . '/tytb_audit_' . bin2hex(random_bytes(4));
        mkdir($project . '/translations', 0777, true);
        file_put_contents($project . '/translations/messages.es.yaml', "a: Uno\n");

        $auditor = $this->createAuditor($project);
        $reports = $auditor->audit('en');

        self::assertFalse($reports[0]['all_ok']);
        self::assertNotNull($reports[0]['source_error']);
    }

    public function testOnlyDomainFiltersDomains(): void
    {
        $project = sys_get_temp_dir() . '/tytb_audit_' . bin2hex(random_bytes(4));
        mkdir($project . '/translations', 0777, true);
        file_put_contents($project . '/translations/messages.en.yaml', "a: One\n");
        file_put_contents($project . '/translations/other.en.yaml', "x: X\n");

        $auditor = $this->createAuditor($project);
        $reports = $auditor->audit('en', 'other');

        self::assertCount(1, $reports);
        self::assertSame('other', $reports[0]['domain']);
    }

    public function testUnknownOnlyDomainReturnsNoReports(): void
    {
        $project = sys_get_temp_dir() . '/tytb_audit_' . bin2hex(random_bytes(4));
        mkdir($project . '/translations', 0777, true);
        file_put_contents($project . '/translations/messages.en.yaml', "a: One\n");

        $auditor = $this->createAuditor($project);
        self::assertSame([], $auditor->audit('en', 'nope'));
    }

    public function testInvalidSourceYamlSetsSourceError(): void
    {
        $project = sys_get_temp_dir() . '/tytb_audit_' . bin2hex(random_bytes(4));
        mkdir($project . '/translations', 0777, true);
        file_put_contents($project . '/translations/messages.en.yaml', "a: [\n");

        $auditor = $this->createAuditor($project);
        $reports = $auditor->audit('en');

        self::assertFalse($reports[0]['all_ok']);
        self::assertNotNull($reports[0]['source_error']);
        self::assertStringContainsString('Invalid YAML', (string) $reports[0]['source_error']);
    }

    public function testTreeConflictMarksLocaleNotTreeSafe(): void
    {
        $project = sys_get_temp_dir() . '/tytb_audit_' . bin2hex(random_bytes(4));
        mkdir($project . '/translations', 0777, true);
        file_put_contents($project . '/translations/messages.en.yaml', "a: leaf\na.b: x\n");

        $auditor = $this->createAuditor($project);
        $reports = $auditor->audit('en');

        self::assertFalse($reports[0]['all_ok']);
        $row = $reports[0]['locales'][0];
        self::assertFalse($row['tree_ok']);
        self::assertGreaterThan(0, $row['tree_conflict_count']);
        self::assertNotSame([], $row['tree_conflict_samples']);
    }

    public function testUnsortedYamlMarksSortedFalse(): void
    {
        $project = sys_get_temp_dir() . '/tytb_audit_' . bin2hex(random_bytes(4));
        mkdir($project . '/translations', 0777, true);
        file_put_contents($project . '/translations/messages.en.yaml', "z: Last\na: First\n");

        $auditor = $this->createAuditor($project);
        $reports = $auditor->audit('en');

        self::assertFalse($reports[0]['all_ok']);
        self::assertFalse($reports[0]['locales'][0]['sorted']);
    }

    public function testInvalidLocaleYamlSetsYamlErrorOnRow(): void
    {
        $project = sys_get_temp_dir() . '/tytb_audit_' . bin2hex(random_bytes(4));
        mkdir($project . '/translations', 0777, true);
        file_put_contents($project . '/translations/messages.en.yaml', "a: One\n");
        file_put_contents($project . '/translations/messages.es.yaml', "a: [\n");

        $auditor = $this->createAuditor($project);
        $reports = $auditor->audit('en');

        self::assertFalse($reports[0]['all_ok']);
        $es = $reports[0]['locales'][1];
        self::assertSame('es', $es['locale']);
        self::assertNotNull($es['yaml_error']);
        self::assertFalse($es['tree_ok']);
        self::assertFalse($es['sorted']);
    }

    public function testSkipsLocaleWhenCatalogResolvesPathToNull(): void
    {
        $project = sys_get_temp_dir() . '/tytb_audit_' . bin2hex(random_bytes(4));
        mkdir($project . '/translations', 0777, true);
        $enPath = $project . '/translations/messages.en.yaml';
        file_put_contents($enPath, "a: One\n");

        $catalog = $this->createMock(TranslationYamlCatalog::class);
        $catalog->method('listDomains')->willReturn(['messages']);
        $catalog->method('listLocalesForDomain')->with('messages')->willReturn(['en', 'es']);
        $catalog->method('resolveFileForDomainLocale')
            ->willReturnCallback(static function (string $domain, string $locale) use ($enPath): ?string {
                return $locale === 'en' ? $enPath : null;
            });

        $auditor = new TranslationYamlAuditor(
            $catalog,
            new TranslationYamlFileHandler(),
            new DotKeyTreeAnalyzer(),
            new YamlArraySorter(),
        );
        $reports = $auditor->audit('en');

        self::assertCount(1, $reports[0]['locales']);
        self::assertSame('en', $reports[0]['locales'][0]['locale']);
    }

    private function createAuditor(string $project): TranslationYamlAuditor
    {
        $kernel = $this->createMock(KernelInterface::class);
        $kernel->method('getProjectDir')->willReturn($project);

        $bag = new ParameterBag([
            'kernel.project_dir'        => $project,
            'translator.default_path'   => $project . '/translations',
            'translator.default_locale' => 'en',
        ]);
        $paths   = new FrameworkTranslationPathsResolver($kernel, $bag);
        $catalog = new TranslationYamlCatalog($paths);

        return new TranslationYamlAuditor(
            $catalog,
            new TranslationYamlFileHandler(),
            new DotKeyTreeAnalyzer(),
            new YamlArraySorter(),
        );
    }
}
