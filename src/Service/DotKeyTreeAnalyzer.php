<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Service;

use function array_key_exists;
use function count;
use function is_array;
use function sprintf;

/**
 * Analyses flattened translation keys (dot paths) for safe conversion to a nested YAML tree.
 */
class DotKeyTreeAnalyzer
{
    /**
     * Flattens a nested associative array into dot-separated keys (leaf values only).
     *
     * @param array<string, mixed> $data Parsed YAML/PHP array
     *
     * @return array<string, mixed> Map of dot key => scalar|array (non-assoc arrays kept as values)
     */
    public function flatten(array $data, string $prefix = ''): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            $segment = (string) $key;
            $path    = $prefix === '' ? $segment : $prefix . '.' . $segment;
            if (is_array($value) && $this->isAssociative($value)) {
                $nested = $this->flatten($value, $path);
                if ($nested === []) {
                    $out[$path] = [];
                } else {
                    foreach ($nested as $k => $v) {
                        $out[$k] = $v;
                    }
                }
            } else {
                $out[$path] = $value;
            }
        }

        return $out;
    }

    /**
     * Returns null if dot keys can be expanded to a tree; otherwise a human-readable conflict description.
     *
     * @param array<string, mixed> $flatLeaves dot key => value
     */
    public function treeConversionConflict(array $flatLeaves): ?string
    {
        $conflicts = $this->collectTreeConversionConflicts($flatLeaves);
        if ($conflicts === []) {
            return null;
        }

        $first = $conflicts[0];

        return sprintf(
            'Cannot build a tree: key "%s" is both a leaf and a prefix of "%s".',
            $first['leaf_key'],
            $first['blocked_key'],
        );
    }

    /**
     * All dot-key conflicts that prevent building a nested tree (leaf key is also a prefix of another key).
     *
     * @param array<string, mixed> $flatLeaves dot key => value
     *
     * @return list<array{type: string, leaf_key: string, blocked_key: string}> type is always {@see self::CONFLICT_LEAF_AND_PREFIX}
     */
    public function collectTreeConversionConflicts(array $flatLeaves): array
    {
        $raw = [];
        foreach (array_keys($flatLeaves) as $fullKey) {
            $parts = explode('.', (string) $fullKey);
            if (count($parts) < 2) {
                continue;
            }
            $prefix = $parts[0];
            for ($i = 1, $max = count($parts); $i < $max; ++$i) {
                if (array_key_exists($prefix, $flatLeaves)) {
                    $raw[] = [
                        'type'        => self::CONFLICT_LEAF_AND_PREFIX,
                        'leaf_key'    => $prefix,
                        'blocked_key' => (string) $fullKey,
                    ];
                    break;
                }
                $prefix .= '.' . $parts[$i];
            }
        }

        return $this->uniqueConflictTuples($raw);
    }

    public const CONFLICT_LEAF_AND_PREFIX = 'leaf_and_prefix';

    /**
     * Expands dot-separated keys into a nested associative array.
     *
     * @param array<string, mixed> $flatLeaves
     *
     * @return array<string, mixed>
     */
    public function unflatten(array $flatLeaves): array
    {
        $tree = [];
        foreach ($flatLeaves as $path => $value) {
            $segments = explode('.', (string) $path);
            $last     = array_pop($segments);
            if ($last === null || $last === '') {
                continue;
            }
            $ref = &$tree;
            foreach ($segments as $segment) {
                if (!isset($ref[$segment]) || !is_array($ref[$segment])) {
                    $ref[$segment] = [];
                }
                $ref = &$ref[$segment];
            }
            $ref[$last] = $value;
        }

        return $tree;
    }

    /**
     * Number of leaf translation entries after flattening (dot paths).
     *
     * @param array<string, mixed> $data
     */
    public function countFlattenedLeaves(array $data): int
    {
        return count($this->flatten($data));
    }

    /**
     * Ensures flattening the transformed structure yields the same leaf map as the expected flat map.
     *
     * @param array<string, mixed> $expectedFlatLeaves dot key => leaf value (as from {@see flatten()})
     * @param array<string, mixed> $transformedNestedOrFlat tree or flat array after sort/tree/inline round-trip
     *
     * @return null if preserved, or a short error message
     */
    public function verifyFlattenedLeavesPreserved(array $expectedFlatLeaves, array $transformedNestedOrFlat): ?string
    {
        $actualFlat = $this->flatten($transformedNestedOrFlat);
        if (count($expectedFlatLeaves) !== count($actualFlat)) {
            return sprintf(
                'Leaf key count mismatch: before %d, after flattening the result %d.',
                count($expectedFlatLeaves),
                count($actualFlat),
            );
        }

        foreach ($expectedFlatLeaves as $key => $value) {
            if (!array_key_exists($key, $actualFlat)) {
                return sprintf('Missing leaf key after transform: "%s".', $key);
            }
            if ($actualFlat[$key] !== $value) {
                return sprintf('Value mismatch for leaf key "%s" after transform.', $key);
            }
        }

        return null;
    }

    /**
     * @param list<array{type: string, leaf_key: string, blocked_key: string}> $items
     *
     * @return list<array{type: string, leaf_key: string, blocked_key: string}>
     */
    private function uniqueConflictTuples(array $items): array
    {
        $seen = [];
        $out  = [];
        foreach ($items as $item) {
            $k = $item['leaf_key'] . "\0" . $item['blocked_key'];
            if (isset($seen[$k])) {
                continue;
            }
            $seen[$k] = true;
            $out[]    = $item;
        }

        return $out;
    }

    /**
     * @param array<mixed> $array
     */
    private function isAssociative(array $array): bool
    {
        if ($array === []) {
            return true;
        }

        return array_keys($array) !== range(0, count($array) - 1);
    }
}
