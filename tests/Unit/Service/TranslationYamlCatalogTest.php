<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Tests\Unit\Service;

use Nowo\TranslationYamlToolsBundle\Service\FrameworkTranslationPathsResolver;
use Nowo\TranslationYamlToolsBundle\Service\TranslationYamlCatalog;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Yaml\Yaml;

#[CoversClass(TranslationYamlCatalog::class)]
final class TranslationYamlCatalogTest extends TestCase
{
    public function testItListsDomainsAndResolvesFiles(): void
    {
        $project      = sys_get_temp_dir() . '/tyt_cat_' . uniqid();
        $translations = $project . '/translations';
        mkdir($translations, 0777, true);
        file_put_contents($translations . '/messages.en.yaml', "a: b\n");
        file_put_contents($translations . '/messages.es.yaml', "a: c\n");

        $kernel = $this->createMock(KernelInterface::class);
        $kernel->method('getProjectDir')->willReturn($project);

        $bag     = new ParameterBag(['kernel.project_dir' => $project]);
        $paths   = new FrameworkTranslationPathsResolver($kernel, $bag);
        $catalog = new TranslationYamlCatalog($paths);

        self::assertSame(['messages'], $catalog->listDomains());
        self::assertSame(['en', 'es'], $catalog->listLocalesForDomain('messages'));
        self::assertStringEndsWith('messages.en.yaml', (string) $catalog->resolveFileForDomainLocale('messages', 'en'));
    }

    public function testItResolvesYmlExtension(): void
    {
        $project      = sys_get_temp_dir() . '/tyt_cat_yml_' . uniqid();
        $translations = $project . '/translations';
        mkdir($translations, 0777, true);
        file_put_contents($translations . '/widgets.fr.yml', "k: v\n");

        $kernel = $this->createMock(KernelInterface::class);
        $kernel->method('getProjectDir')->willReturn($project);
        $bag     = new ParameterBag(['kernel.project_dir' => $project]);
        $catalog = new TranslationYamlCatalog(new FrameworkTranslationPathsResolver($kernel, $bag));

        self::assertSame(['widgets'], $catalog->listDomains());
        $path = $catalog->resolveFileForDomainLocale('widgets', 'fr');
        self::assertNotNull($path);
        self::assertStringEndsWith('.yml', $path);
    }

    public function testItAggregatesDomainsFromMultipleDirectories(): void
    {
        $project = sys_get_temp_dir() . '/tyt_cat_multi_' . uniqid();
        $a       = $project . '/translations';
        $b       = $project . '/more';
        mkdir($a, 0777, true);
        mkdir($b, 0777, true);
        mkdir($project . '/config/packages', 0777, true);
        file_put_contents($a . '/a.en.yaml', "x: 1\n");
        file_put_contents($b . '/b.en.yaml', "y: 2\n");
        file_put_contents($project . '/config/packages/translation.yaml', Yaml::dump([
            'framework' => [
                'translator' => [
                    'paths' => [$b],
                ],
            ],
        ]));

        $kernel = $this->createMock(KernelInterface::class);
        $kernel->method('getProjectDir')->willReturn($project);
        $bag = new ParameterBag([
            'kernel.project_dir'      => $project,
            'translator.default_path' => $a,
        ]);
        $catalog = new TranslationYamlCatalog(new FrameworkTranslationPathsResolver($kernel, $bag));

        self::assertSame(['a', 'b'], $catalog->listDomains());
    }

    public function testItSkipsScanWhenConfiguredPathIsNotADirectory(): void
    {
        $paths = $this->createMock(FrameworkTranslationPathsResolver::class);
        $paths->method('resolveTranslationDirectories')->willReturn([
            sys_get_temp_dir() . '/tyt_not_a_dir_' . uniqid(),
        ]);
        $catalog = new TranslationYamlCatalog($paths);
        self::assertSame([], $catalog->listDomains());
    }

    public function testItReturnsNullWhenNoMatchingFileExists(): void
    {
        $project      = sys_get_temp_dir() . '/tyt_cat_null_' . uniqid();
        $translations = $project . '/translations';
        mkdir($translations, 0777, true);
        file_put_contents($translations . '/messages.en.yaml', "a: b\n");

        $kernel = $this->createMock(KernelInterface::class);
        $kernel->method('getProjectDir')->willReturn($project);
        $bag     = new ParameterBag(['kernel.project_dir' => $project]);
        $catalog = new TranslationYamlCatalog(new FrameworkTranslationPathsResolver($kernel, $bag));

        self::assertNull($catalog->resolveFileForDomainLocale('messages', 'zz'));
    }
}
