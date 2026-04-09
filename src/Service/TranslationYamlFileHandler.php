<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Service;

use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

use function dirname;
use function is_array;
use function sprintf;

/**
 * Loads and dumps Symfony-friendly YAML translation files.
 */
final class TranslationYamlFileHandler
{
    /**
     * @return array<string, mixed>
     */
    public function loadFile(string $path): array
    {
        if (!is_file($path)) {
            throw new InvalidArgumentException(sprintf('Translation file not found: %s', $path));
        }

        try {
            $parsed = Yaml::parseFile($path);
        } catch (ParseException $e) {
            throw new InvalidArgumentException(sprintf('Invalid YAML in %s: %s', $path, $e->getMessage()), 0, $e);
        }

        if (!is_array($parsed)) {
            return [];
        }

        /* @var array<string, mixed> $parsed */
        return $parsed;
    }

    /**
     * @param array<string, mixed> $data
     * @param bool $inline When true, dump in compact YAML flow style (e.g. `{ a: b, c: d }`).
     */
    public function dumpToFile(string $path, array $data, int $indentSpaces, bool $inline = false): void
    {
        if ($indentSpaces < 2 || $indentSpaces > 12) {
            throw new InvalidArgumentException('YAML indent must be between 2 and 12.');
        }

        if ($inline) {
            $yaml = Yaml::dump($data, 0, $indentSpaces, 0);
        } else {
            $yaml = Yaml::dump(
                $data,
                20,
                $indentSpaces,
                Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK,
            );
        }

        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException(sprintf('Cannot create directory: %s', $dir));
        }

        if (file_put_contents($path, $yaml) === false) {
            throw new RuntimeException(sprintf('Cannot write file: %s', $path));
        }
    }
}
