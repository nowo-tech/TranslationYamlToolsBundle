<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Tests\Unit\Service;

use InvalidArgumentException;
use Nowo\TranslationYamlToolsBundle\Service\TranslationYamlFileHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use const DIRECTORY_SEPARATOR;

#[CoversClass(TranslationYamlFileHandler::class)]
final class TranslationYamlFileHandlerTest extends TestCase
{
    public function testLoadFileThrowsWhenMissing(): void
    {
        $handler = new TranslationYamlFileHandler();
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Translation file not found');
        $handler->loadFile('/nonexistent/path/messages.en.yaml');
    }

    public function testLoadFileThrowsOnInvalidYaml(): void
    {
        $path = sys_get_temp_dir() . '/tyt_bad_' . uniqid() . '.yaml';
        file_put_contents($path, "{not: valid: yaml: [\n");
        try {
            $handler = new TranslationYamlFileHandler();
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('Invalid YAML');
            $handler->loadFile($path);
        } finally {
            @unlink($path);
        }
    }

    public function testLoadFileReturnsEmptyArrayForScalarRoot(): void
    {
        $path = sys_get_temp_dir() . '/tyt_scalar_' . uniqid() . '.yaml';
        file_put_contents($path, '"just a string"');
        $handler = new TranslationYamlFileHandler();
        self::assertSame([], $handler->loadFile($path));
        @unlink($path);
    }

    public function testLoadAndRoundTripDump(): void
    {
        $dir = sys_get_temp_dir() . '/tyt_rt_' . uniqid();
        mkdir($dir, 0777, true);
        $path    = $dir . '/messages.en.yaml';
        $handler = new TranslationYamlFileHandler();
        $handler->dumpToFile($path, ['z' => 1, 'a' => ['b' => 2]], 4);
        $loaded = $handler->loadFile($path);
        self::assertSame(['z' => 1, 'a' => ['b' => 2]], $loaded);
    }

    public function testDumpInlineWritesFlowStyleYaml(): void
    {
        $dir = sys_get_temp_dir() . '/tyt_inline_' . uniqid();
        mkdir($dir, 0777, true);
        $path    = $dir . '/x.yaml';
        $handler = new TranslationYamlFileHandler();
        $handler->dumpToFile($path, ['z' => 1, 'a' => ['b' => 2]], 4, true);
        $raw = (string) file_get_contents($path);
        self::assertStringContainsString('{', $raw);
        self::assertSame(['z' => 1, 'a' => ['b' => 2]], $handler->loadFile($path));
    }

    public function testDumpToFileRejectsIndentOutOfRange(): void
    {
        $handler = new TranslationYamlFileHandler();
        $path    = sys_get_temp_dir() . '/tyt_indent_' . uniqid() . '.yaml';
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('YAML indent must be between 2 and 12');
        $handler->dumpToFile($path, ['a' => 'b'], 1);
    }

    public function testDumpToFileThrowsWhenDirectoryCannotBeCreated(): void
    {
        if ('\\' === DIRECTORY_SEPARATOR) {
            self::markTestSkipped('chmod-based permission test is Unix-specific.');
        }
        $handler     = new TranslationYamlFileHandler();
        $fileBlocker = tempnam(sys_get_temp_dir(), 'tyt_block_');
        self::assertNotFalse($fileBlocker);
        $path = $fileBlocker . '/nested/file.yaml';
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot create directory');
        $previous = error_reporting(0);
        try {
            $handler->dumpToFile($path, ['x' => 'y'], 4);
        } finally {
            error_reporting($previous);
            @unlink($fileBlocker);
        }
    }

    public function testDumpToFileThrowsWhenPathIsDirectory(): void
    {
        $dir = sys_get_temp_dir() . '/tyt_isdir_' . uniqid();
        mkdir($dir, 0777, true);
        $handler = new TranslationYamlFileHandler();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot write file');
        $previous = error_reporting(0);
        try {
            $handler->dumpToFile($dir, ['a' => 'b'], 4);
        } finally {
            error_reporting($previous);
            @rmdir($dir);
        }
    }

    public function testLoadFileRejectsOversizedFile(): void
    {
        $path = sys_get_temp_dir() . '/tyt_big_' . uniqid() . '.yaml';
        file_put_contents($path, "a: b\n");
        $handler = new TranslationYamlFileHandler(maxFileBytes: 1);
        try {
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('exceeds max size');
            $handler->loadFile($path);
        } finally {
            @unlink($path);
        }
    }

    public function testLoadFileRejectsExcessiveDepth(): void
    {
        $path = sys_get_temp_dir() . '/tyt_deep_' . uniqid() . '.yaml';
        file_put_contents($path, "a:\n  b:\n    c: 1\n");
        $handler = new TranslationYamlFileHandler(maxDepth: 1);
        try {
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('exceeds max depth');
            $handler->loadFile($path);
        } finally {
            @unlink($path);
        }
    }

    public function testLoadFileRejectsExcessiveNodes(): void
    {
        $path = sys_get_temp_dir() . '/tyt_nodes_' . uniqid() . '.yaml';
        file_put_contents($path, "a: 1\nb: 2\nc: 3\n");
        $handler = new TranslationYamlFileHandler(maxNodes: 2);
        try {
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('exceeds max nodes');
            $handler->loadFile($path);
        } finally {
            @unlink($path);
        }
    }
}
