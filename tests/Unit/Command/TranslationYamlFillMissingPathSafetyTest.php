<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Tests\Unit\Command;

use InvalidArgumentException;
use Nowo\TranslationYamlToolsBundle\Command\TranslationYamlFillMissingCommand;
use Nowo\TranslationYamlToolsBundle\MachineTranslation\MachineTranslatorInterface;
use Nowo\TranslationYamlToolsBundle\Service\DotKeyTreeAnalyzer;
use Nowo\TranslationYamlToolsBundle\Service\FrameworkTranslationPathsResolver;
use Nowo\TranslationYamlToolsBundle\Service\TranslationDefaultLocaleResolver;
use Nowo\TranslationYamlToolsBundle\Service\TranslationYamlCatalog;
use Nowo\TranslationYamlToolsBundle\Service\TranslationYamlFileHandler;
use Nowo\TranslationYamlToolsBundle\Service\YamlArraySorter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

use const DIRECTORY_SEPARATOR;

#[CoversClass(TranslationYamlFillMissingCommand::class)]
final class TranslationYamlFillMissingPathSafetyTest extends TestCase
{
    private function command(FrameworkTranslationPathsResolver $paths): TranslationYamlFillMissingCommand
    {
        return new TranslationYamlFillMissingCommand(
            $this->createMock(TranslationYamlCatalog::class),
            $paths,
            new TranslationYamlFileHandler(),
            $this->createMock(TranslationDefaultLocaleResolver::class),
            new DotKeyTreeAnalyzer(),
            new YamlArraySorter(),
            $this->createMock(MachineTranslatorInterface::class),
            null,
            4,
            'google',
            false,
        );
    }

    /**
     * @param list<string> $dirs
     */
    private function assertPath(TranslationYamlFillMissingCommand $cmd, string $path, array $dirs): void
    {
        $method = new ReflectionMethod(TranslationYamlFillMissingCommand::class, 'assertPathUnderTranslationDirectories');
        $method->invoke($cmd, $path, $dirs);
    }

    public function testSkipsEmptyAndUncreatableDirsThenAcceptsValidRoot(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            self::markTestSkipped('chmod-based permission test is Unix-specific.');
        }

        $allowed = sys_get_temp_dir() . '/tyt_fill_ok_' . uniqid('', true);
        mkdir($allowed, 0777, true);
        $blocker = tempnam(sys_get_temp_dir(), 'tyt_fill_block_');
        self::assertNotFalse($blocker);

        $cmd = $this->command($this->createMock(FrameworkTranslationPathsResolver::class));
        try {
            @$this->assertPath($cmd, $allowed . '/messages.de.yaml', ['', $blocker . '/impossible', $allowed]);
        } finally {
            @unlink($blocker);
        }
        self::assertTrue(is_dir($allowed));
    }

    public function testCreatesCwdTranslationsFallbackWhenNoRootsResolve(): void
    {
        $cwd = getcwd();
        self::assertNotFalse($cwd);
        $fallback = rtrim($cwd, '/') . '/translations';
        $existed  = is_dir($fallback);

        try {
            $cmd = $this->command($this->createMock(FrameworkTranslationPathsResolver::class));
            $this->assertPath($cmd, $fallback . '/messages.de.yaml', []);
            self::assertDirectoryExists($fallback);
        } finally {
            if (!$existed) {
                @rmdir($fallback);
            }
        }
    }

    public function testThrowsWhenParentDirectoryCannotBeCreated(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            self::markTestSkipped('file-as-parent mkdir failure is Unix-specific.');
        }

        $allowed = sys_get_temp_dir() . '/tyt_fill_root_' . uniqid('', true);
        mkdir($allowed, 0777, true);
        $blocker = tempnam(sys_get_temp_dir(), 'tyt_fill_par_');
        self::assertNotFalse($blocker);

        $cmd = $this->command($this->createMock(FrameworkTranslationPathsResolver::class));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot create translation directory');
        try {
            @$this->assertPath($cmd, $blocker . '/nested/messages.de.yaml', [$allowed]);
        } finally {
            @unlink($blocker);
        }
    }

    public function testThrowsWhenPathIsOutsideAllowedRoots(): void
    {
        $allowed = sys_get_temp_dir() . '/tyt_fill_in_' . uniqid('', true);
        $outside = sys_get_temp_dir() . '/tyt_fill_out_' . uniqid('', true);
        mkdir($allowed, 0777, true);
        mkdir($outside, 0777, true);

        $cmd = $this->command($this->createMock(FrameworkTranslationPathsResolver::class));
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Refusing to write translation file outside configured translation directories');
        $this->assertPath($cmd, $outside . '/messages.de.yaml', [$allowed]);
    }
}
