<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Tests\Unit\Service;

use Nowo\TranslationYamlToolsBundle\Service\FrameworkTranslationPathsResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Yaml\Yaml;

#[CoversClass(FrameworkTranslationPathsResolver::class)]
final class FrameworkTranslationPathsResolverTest extends TestCase
{
    public function testItUsesTranslatorDefaultPathParameterWhenSet(): void
    {
        $custom = sys_get_temp_dir() . '/tyt_custom_' . uniqid();
        mkdir($custom, 0777, true);

        $kernel = $this->createMock(KernelInterface::class);
        $kernel->method('getProjectDir')->willReturn(sys_get_temp_dir() . '/tyt_proj_' . uniqid());

        $bag = new ParameterBag([
            'kernel.project_dir'      => sys_get_temp_dir(),
            'translator.default_path' => $custom,
        ]);

        $resolver = new FrameworkTranslationPathsResolver($kernel, $bag);
        $dirs     = $resolver->resolveTranslationDirectories();

        self::assertSame([$custom], $dirs);
    }

    public function testItFallsBackToProjectTranslationsWithoutParameter(): void
    {
        $project = sys_get_temp_dir() . '/tyt_proj_' . uniqid();
        mkdir($project . '/translations', 0777, true);

        $kernel = $this->createMock(KernelInterface::class);
        $kernel->method('getProjectDir')->willReturn($project);

        $bag = new ParameterBag([
            'kernel.project_dir' => $project,
        ]);

        $resolver = new FrameworkTranslationPathsResolver($kernel, $bag);
        $dirs     = $resolver->resolveTranslationDirectories();

        self::assertSame([$project . '/translations'], $dirs);
    }

    public function testItDescribesSourcesWhenTranslatorDefaultPathSet(): void
    {
        $kernel = $this->createMock(KernelInterface::class);
        $bag    = new ParameterBag([
            'kernel.project_dir'      => '/tmp',
            'translator.default_path' => '/tmp/t',
        ]);
        $resolver = new FrameworkTranslationPathsResolver($kernel, $bag);
        $lines    = $resolver->describeResolutionSources();
        self::assertStringContainsString('translator.default_path is set', $lines[0]);
    }

    public function testItDescribesFallbackWhenTranslatorDefaultPathMissing(): void
    {
        $kernel   = $this->createMock(KernelInterface::class);
        $bag      = new ParameterBag(['kernel.project_dir' => '/tmp']);
        $resolver = new FrameworkTranslationPathsResolver($kernel, $bag);
        $lines    = $resolver->describeResolutionSources();
        self::assertStringContainsString('not set', $lines[0]);
    }

    public function testItMergesPathsFromFrameworkTranslationYaml(): void
    {
        $project      = sys_get_temp_dir() . '/tyt_fw_' . uniqid();
        $translations = $project . '/translations';
        $extra        = $project . '/translations_extra';
        mkdir($translations, 0777, true);
        mkdir($extra, 0777, true);
        mkdir($project . '/config/packages', 0777, true);
        file_put_contents($project . '/config/packages/translation.yaml', Yaml::dump([
            'framework' => [
                'translator' => [
                    'default_path' => $extra,
                    'paths'        => [$translations, 99, ''],
                ],
            ],
        ]));

        $kernel = $this->createMock(KernelInterface::class);
        $kernel->method('getProjectDir')->willReturn($project);

        $bag = new ParameterBag([
            'kernel.project_dir'      => $project,
            'translator.default_path' => $translations,
        ]);
        $resolver = new FrameworkTranslationPathsResolver($kernel, $bag);
        $dirs     = $resolver->resolveTranslationDirectories();
        self::assertEqualsCanonicalizing([$extra, $translations], $dirs);
    }

    public function testItSkipsInvalidYamlAndNonArrayFrameworkSections(): void
    {
        $project = sys_get_temp_dir() . '/tyt_fw2_' . uniqid();
        mkdir($project . '/config/packages', 0777, true);
        file_put_contents($project . '/config/packages/translation.yaml', '{{broken');
        file_put_contents($project . '/config/packages/dev/translation.yaml', Yaml::dump(['framework' => 'nope']));
        mkdir($project . '/translations', 0777, true);

        $kernel = $this->createMock(KernelInterface::class);
        $kernel->method('getProjectDir')->willReturn($project);
        $bag      = new ParameterBag(['kernel.project_dir' => $project]);
        $resolver = new FrameworkTranslationPathsResolver($kernel, $bag);
        $dirs     = $resolver->resolveTranslationDirectories();
        self::assertSame([$project . '/translations'], $dirs);
    }

    public function testItDiscoversTranslationYmlInEnvironmentSubfolder(): void
    {
        $project = sys_get_temp_dir() . '/tyt_fw3_' . uniqid();
        mkdir($project . '/config/packages/staging', 0777, true);
        $extra = $project . '/extra_tr';
        mkdir($extra, 0777, true);
        file_put_contents($project . '/config/packages/staging/translation.yml', Yaml::dump([
            'framework' => [
                'translator' => [
                    'paths' => [$extra],
                ],
            ],
        ]));
        mkdir($project . '/translations', 0777, true);

        $kernel = $this->createMock(KernelInterface::class);
        $kernel->method('getProjectDir')->willReturn($project);
        $bag      = new ParameterBag(['kernel.project_dir' => $project]);
        $resolver = new FrameworkTranslationPathsResolver($kernel, $bag);
        self::assertContains($extra, $resolver->resolveTranslationDirectories());
    }

    public function testItDoesNotScanWhenConfigPackagesDirectoryIsMissing(): void
    {
        $project = sys_get_temp_dir() . '/tyt_nopkg_' . uniqid();
        mkdir($project . '/translations', 0777, true);
        self::assertDirectoryDoesNotExist($project . '/config');

        $kernel = $this->createMock(KernelInterface::class);
        $kernel->method('getProjectDir')->willReturn($project);
        $bag = new ParameterBag([
            'kernel.project_dir'      => $project,
            'translator.default_path' => $project . '/translations',
        ]);
        $resolver = new FrameworkTranslationPathsResolver($kernel, $bag);
        self::assertSame([$project . '/translations'], $resolver->resolveTranslationDirectories());
    }

    public function testItFiltersOutNonexistentDirectories(): void
    {
        $project = sys_get_temp_dir() . '/tyt_fw4_' . uniqid();
        mkdir($project . '/translations', 0777, true);
        mkdir($project . '/config/packages', 0777, true);
        file_put_contents($project . '/config/packages/translation.yaml', Yaml::dump([
            'framework' => ['translator' => ['paths' => [$project . '/missing_dir']]],
        ]));

        $kernel = $this->createMock(KernelInterface::class);
        $kernel->method('getProjectDir')->willReturn($project);
        $bag = new ParameterBag([
            'kernel.project_dir'      => $project,
            'translator.default_path' => $project . '/translations',
        ]);
        $resolver = new FrameworkTranslationPathsResolver($kernel, $bag);
        self::assertSame([$project . '/translations'], $resolver->resolveTranslationDirectories());
    }

    public function testItSkipsConfigWhenTranslatorSectionIsNotAnArray(): void
    {
        $project = sys_get_temp_dir() . '/tyt_tr_null_' . uniqid();
        mkdir($project . '/config/packages', 0777, true);
        mkdir($project . '/translations', 0777, true);
        file_put_contents($project . '/config/packages/translation.yaml', Yaml::dump([
            'framework' => [
                'translator' => null,
            ],
        ]));
        file_put_contents($project . '/config/packages/translation.yml', Yaml::dump([
            'framework' => [
                'translator' => 'scalar',
            ],
        ]));

        $kernel = $this->createMock(KernelInterface::class);
        $kernel->method('getProjectDir')->willReturn($project);
        $bag      = new ParameterBag(['kernel.project_dir' => $project]);
        $resolver = new FrameworkTranslationPathsResolver($kernel, $bag);
        self::assertSame([$project . '/translations'], $resolver->resolveTranslationDirectories());
    }

    public function testItSkipsConfigWhenFrameworkValueIsNotAnArray(): void
    {
        $project = sys_get_temp_dir() . '/tyt_fw_scalar_' . uniqid();
        mkdir($project . '/config/packages/scalar_fw', 0777, true);
        mkdir($project . '/translations', 0777, true);
        file_put_contents($project . '/config/packages/scalar_fw/translation.yaml', "framework: 123\n");

        $kernel = $this->createMock(KernelInterface::class);
        $kernel->method('getProjectDir')->willReturn($project);
        $bag      = new ParameterBag(['kernel.project_dir' => $project]);
        $resolver = new FrameworkTranslationPathsResolver($kernel, $bag);
        self::assertSame([$project . '/translations'], $resolver->resolveTranslationDirectories());
    }

    public function testItLoadsRootTranslationYmlAlongsideYaml(): void
    {
        $project = sys_get_temp_dir() . '/tyt_root_yml_' . uniqid();
        mkdir($project . '/config/packages', 0777, true);
        $extra = $project . '/from_yml';
        mkdir($extra, 0777, true);
        file_put_contents($project . '/config/packages/translation.yml', Yaml::dump([
            'framework' => [
                'translator' => [
                    'paths' => [$extra],
                ],
            ],
        ]));
        mkdir($project . '/translations', 0777, true);

        $kernel = $this->createMock(KernelInterface::class);
        $kernel->method('getProjectDir')->willReturn($project);
        $bag      = new ParameterBag(['kernel.project_dir' => $project]);
        $resolver = new FrameworkTranslationPathsResolver($kernel, $bag);
        self::assertContains($extra, $resolver->resolveTranslationDirectories());
    }

    public function testItSkipsOversizedTranslationConfigFiles(): void
    {
        $project = sys_get_temp_dir() . '/tyt_fw_big_' . uniqid();
        mkdir($project . '/config/packages', 0777, true);
        mkdir($project . '/translations', 0777, true);
        $hugePath = $project . '/config/packages/translation.yaml';
        $payload  = "framework:\n  translator:\n    paths:\n      - " . $project . "/from_huge\n";
        // Exceed DEFAULT_MAX_FILE_BYTES so pathsFromFrameworkYaml skips the file (line with filesize check).
        file_put_contents($hugePath, str_repeat('#', 2_097_153) . "\n" . $payload);

        $kernel = $this->createMock(KernelInterface::class);
        $kernel->method('getProjectDir')->willReturn($project);
        $bag      = new ParameterBag(['kernel.project_dir' => $project]);
        $resolver = new FrameworkTranslationPathsResolver($kernel, $bag);
        $dirs     = $resolver->resolveTranslationDirectories();
        self::assertSame([$project . '/translations'], $dirs);
        self::assertNotContains($project . '/from_huge', $dirs);
    }
}
