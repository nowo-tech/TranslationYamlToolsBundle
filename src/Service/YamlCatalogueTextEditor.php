<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Service;

use InvalidArgumentException;
use Symfony\Component\Yaml\Yaml;

use function array_slice;
use function count;
use function explode;
use function implode;
use function is_string;
use function ltrim;
use function preg_match;
use function preg_quote;
use function sprintf;
use function str_contains;
use function str_repeat;
use function str_replace;
use function str_starts_with;
use function strcmp;
use function strlen;
use function substr;
use function trim;

/**
 * Textual edits of one nested YAML translation catalogue: sets or removes a dotted key while the rest of the file keeps
 * its quoting, order, blank lines and comments (no full re-dump).
 *
 * The key is a dotted path (`nav.support_inbox`); a segment that itself contains dots is matched when such a key
 * exists in the file (`app.title` under a flat file). A new key is placed in alphabetical position when its siblings
 * are already sorted, else at the end of its parent block; missing parent levels are created.
 *
 * Callers must re-parse the result: block scalars, flow mappings or anchors the walker does not understand surface as
 * a value that does not read back.
 */
final class YamlCatalogueTextEditor
{
    /**
     * Returns the text with $key set to $value.
     *
     * @param int $fallbackIndent spaces per level when the file has no indented line yet
     *
     * @throws InvalidArgumentException when the key exists and $replace is false, when it is a group of keys, or when
     *                                  a parent level is a value
     */
    public function set(string $text, string $key, string|int|float|bool|null $value, bool $replace, int $fallbackIndent = 4): string
    {
        $lines    = explode("\n", $text);
        $unit     = $this->indentUnit($text, $fallbackIndent);
        $segments = $this->segments($key);
        $located  = $this->locate($lines, $segments, $unit);

        if ($located['rest'] === []) {
            $frame = $located['frames'][count($located['frames']) - 1];
            if (!$replace) {
                throw new InvalidArgumentException(sprintf('Key "%s" already exists (pass --replace to overwrite).', $key));
            }
            if ($this->isGroup($lines, $frame)) {
                throw new InvalidArgumentException(sprintf('Key "%s" is a group of keys, not a value.', $key));
            }
            array_splice($lines, $frame['at'], $frame['end'] - $frame['at'], [$frame['head'] . ' ' . $this->formatScalar($value)]);

            return implode("\n", $lines);
        }

        if ($located['frames'] !== []) {
            $parent = $located['frames'][count($located['frames']) - 1];
            if (!$this->isGroup($lines, $parent) && $this->inlineValue($lines[$parent['at']], $parent['head']) !== '') {
                throw new InvalidArgumentException(sprintf('Cannot add "%s": "%s" is a value, not a group of keys.', $key, $parent['path']));
            }
        }

        $rest     = $located['rest'];
        $depth    = count($located['frames']);
        $siblings = $this->siblings($lines, $located['start'], $located['end'], $depth * $unit);
        foreach ($siblings as $sibling) {
            if (str_contains($sibling['name'], '.')) {
                // Flat (dot-key) level: keep the style and write the rest as one dotted key.
                $rest = [implode('.', $rest)];
                break;
            }
        }
        $at  = $this->insertionIndex($lines, $located['start'], $located['end'], $depth * $unit, $rest[0], $siblings);
        $new = [];
        foreach ($rest as $n => $segment) {
            $line  = str_repeat(' ', ($depth + $n) * $unit) . $this->formatKey($segment) . ':';
            $new[] = $n === count($rest) - 1 ? $line . ' ' . $this->formatScalar($value) : $line;
        }
        array_splice($lines, $at, 0, $new);

        return implode("\n", $lines);
    }

    /**
     * Returns the text without $key (a value or a whole group). Parent groups left without keys are removed too.
     *
     * @throws InvalidArgumentException when the key does not exist
     */
    public function remove(string $text, string $key, int $fallbackIndent = 4): string
    {
        $lines   = explode("\n", $text);
        $unit    = $this->indentUnit($text, $fallbackIndent);
        $located = $this->locate($lines, $this->segments($key), $unit);
        if ($located['rest'] !== []) {
            throw new InvalidArgumentException(sprintf('Key "%s" does not exist.', $key));
        }

        $frames  = $located['frames'];
        $frame   = $frames[count($frames) - 1];
        $removed = $frame['end'] - $frame['at'];
        array_splice($lines, $frame['at'], $removed);

        for ($i = count($frames) - 2; $i >= 0; --$i) {
            $parent = $frames[$i];
            $end    = $parent['end'] - $removed;
            if ($this->inlineValue($lines[$parent['at']], $parent['head']) !== '' || $this->hasBody($lines, $parent['at'] + 1, $end)) {
                break;
            }
            array_splice($lines, $parent['at'], $end - $parent['at']);
            $removed += $end - $parent['at'];
        }

        return implode("\n", $lines);
    }

    /**
     * One-line YAML scalar: single-quoted strings (as hand-written catalogues do), double-quoted with escapes when the
     * string holds line breaks or control characters, YAML literals for other scalars.
     */
    public function formatScalar(string|int|float|bool|null $value): string
    {
        if (is_string($value) && preg_match('/[\x00-\x1f\x7f]/', $value) !== 1) {
            return "'" . str_replace("'", "''", $value) . "'";
        }

        return Yaml::dump($value);
    }

    /**
     * @return list<string>
     */
    private function segments(string $key): array
    {
        if ($key === '' || str_starts_with($key, '.') || str_contains($key, '..') || $key[strlen($key) - 1] === '.') {
            throw new InvalidArgumentException(sprintf('Invalid key "%s": expected a dotted path such as nav.title.', $key));
        }

        return explode('.', $key);
    }

    private function formatKey(string $segment): string
    {
        return preg_match('/^[A-Za-z0-9_][A-Za-z0-9_.-]*$/', $segment) === 1 ? $segment : $this->formatScalar($segment);
    }

    private function indentUnit(string $text, int $fallback): int
    {
        return preg_match('/^( +)[^\s#]/m', $text, $m) === 1 ? strlen($m[1]) : $fallback;
    }

    private function isBody(string $line): bool
    {
        $trimmed = trim($line);

        return $trimmed !== '' && !str_starts_with($trimmed, '#');
    }

    private function indentOf(string $line): int
    {
        return strlen($line) - strlen(ltrim($line, ' '));
    }

    /**
     * @param list<string> $lines
     */
    private function hasBody(array $lines, int $start, int $end): bool
    {
        for ($i = $start; $i < $end; ++$i) {
            if ($this->isBody($lines[$i])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Text after the key on its line, without a trailing comment.
     */
    private function inlineValue(string $line, string $head): string
    {
        $after = trim(substr($line, strlen($head)));

        // A comment, or only an anchor / tag before the nested block (`defaults: &defaults`).
        return str_starts_with($after, '#') || preg_match('/^(?:[&!]\S*\s*)+(?:#.*)?$/', $after) === 1 ? '' : $after;
    }

    /**
     * @param list<string> $lines
     * @param array{at: int, end: int, head: string, path: string} $frame
     */
    private function isGroup(array $lines, array $frame): bool
    {
        return $this->inlineValue($lines[$frame['at']], $frame['head']) === '' && $this->hasBody($lines, $frame['at'] + 1, $frame['end']);
    }

    /**
     * Walks the indentation blocks along $segments (longest dotted name first at each level).
     *
     * @param list<string> $lines
     * @param list<string> $segments
     *
     * @return array{frames: list<array{at: int, end: int, head: string, path: string}>, rest: list<string>, start: int, end: int}
     */
    private function locate(array $lines, array $segments, int $unit): array
    {
        $frames = [];
        $start  = 0;
        $end    = count($lines);
        $path   = '';

        while ($segments !== []) {
            $indent = count($frames) * $unit;
            $found  = null;
            for ($take = count($segments); $take >= 1 && $found === null; --$take) {
                $name    = implode('.', array_slice($segments, 0, $take));
                $q       = preg_quote($name, '/');
                $pattern = '/^' . str_repeat(' ', $indent) . '(?:' . $q . '|\'' . str_replace("'", "''", $q) . '\'|"' . $q . '"):(?=\s|$)/';
                for ($i = $start; $i < $end; ++$i) {
                    if ($this->isBody($lines[$i]) && $this->indentOf($lines[$i]) === $indent && preg_match($pattern, $lines[$i], $m) === 1) {
                        $found = ['at' => $i, 'take' => $take, 'head' => $m[0], 'name' => $name];
                        break;
                    }
                }
            }

            if ($found === null) {
                break;
            }

            $blockEnd = $found['at'] + 1;
            while ($blockEnd < $end && (!$this->isBody($lines[$blockEnd]) || $this->indentOf($lines[$blockEnd]) > $indent)) {
                ++$blockEnd;
            }
            // Trailing blank lines and comments belong to whatever follows the block.
            while ($blockEnd - 1 > $found['at'] && !$this->isBody($lines[$blockEnd - 1])) {
                --$blockEnd;
            }

            $path     = $path === '' ? $found['name'] : $path . '.' . $found['name'];
            $frames[] = ['at' => $found['at'], 'end' => $blockEnd, 'head' => $found['head'], 'path' => $path];
            $segments = array_slice($segments, $found['take']);
            $start    = $found['at'] + 1;
            $end      = $blockEnd;
        }

        return ['frames' => $frames, 'rest' => $segments, 'start' => $start, 'end' => $end];
    }

    /**
     * Keys written at $indent inside [$start, $end), in file order.
     *
     * @param list<string> $lines
     *
     * @return list<array{at: int, name: string}>
     */
    private function siblings(array $lines, int $start, int $end, int $indent): array
    {
        $siblings = [];
        for ($i = $start; $i < $end; ++$i) {
            if ($this->isBody($lines[$i]) && $this->indentOf($lines[$i]) === $indent
                && preg_match('/^\s*(?:\'((?:[^\']|\'\')*)\'|"([^"]*)"|([^:#\s\'"][^:]*?)):(?:\s|$)/', $lines[$i], $m) === 1) {
                $name       = ($m[3] ?? '') !== '' ? $m[3] : (($m[2] ?? '') !== '' ? $m[2] : str_replace("''", "'", $m[1]));
                $siblings[] = ['at' => $i, 'name' => $name];
            }
        }

        return $siblings;
    }

    /**
     * Line index where a new sibling named $segment goes inside [$start, $end) at $indent: alphabetical position when
     * the siblings are sorted, else after the last line of the block.
     *
     * @param list<string> $lines
     * @param list<array{at: int, name: string}> $siblings
     */
    private function insertionIndex(array $lines, int $start, int $end, int $indent, string $segment, array $siblings): int
    {
        $sorted = true;
        for ($i = 1, $n = count($siblings); $i < $n; ++$i) {
            if (strcmp($siblings[$i - 1]['name'], $siblings[$i]['name']) > 0) {
                $sorted = false;
                break;
            }
        }

        if ($sorted) {
            foreach ($siblings as $sibling) {
                if (strcmp($sibling['name'], $segment) > 0) {
                    $at = $sibling['at'];
                    // Keep a comment written right above the sibling attached to it.
                    while ($at > $start && str_starts_with(ltrim($lines[$at - 1]), '#') && $this->indentOf($lines[$at - 1]) === $indent) {
                        --$at;
                    }

                    return $at;
                }
            }
        }

        $last = $start - 1;
        for ($i = $start; $i < $end; ++$i) {
            if ($this->isBody($lines[$i])) {
                $last = $i;
            }
        }

        return $last + 1;
    }
}
