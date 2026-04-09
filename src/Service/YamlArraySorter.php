<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Service;

use function array_key_exists;
use function count;
use function is_array;

use const SORT_STRING;

/**
 * Recursively sorts associative array keys alphabetically (locale-sensitive string order).
 */
final class YamlArraySorter
{
    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function sortAssociativeRecursive(array $data): array
    {
        if (!$this->isAssociative($data)) {
            return $data;
        }

        $sorted = [];
        foreach ($data as $key => $value) {
            $sorted[(string) $key] = is_array($value)
                ? $this->sortAssociativeRecursive($value)
                : $value;
        }
        ksort($sorted, SORT_STRING);

        return $sorted;
    }

    /**
     * True when associative keys are in locale-sensitive alphabetical order at every nested associative level.
     *
     * @param array<string, mixed> $data
     */
    public function isRecursivelySorted(array $data): bool
    {
        $sorted = $this->sortAssociativeRecursive($data);

        return $this->deepEqual($data, $sorted);
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

    private function deepEqual(mixed $a, mixed $b): bool
    {
        if (is_array($a) !== is_array($b)) {
            return false;
        }
        if (!is_array($a)) {
            return $a === $b;
        }
        $aAssoc = $this->isAssociative($a);
        $bAssoc = $this->isAssociative($b);
        if ($aAssoc !== $bAssoc) {
            return false;
        }
        if (!$aAssoc) {
            if (count($a) !== count($b)) {
                return false;
            }
            foreach ($a as $i => $v) {
                if (!array_key_exists($i, $b) || !$this->deepEqual($v, $b[$i])) {
                    return false;
                }
            }

            return true;
        }
        if (array_keys($a) !== array_keys($b)) {
            return false;
        }
        foreach ($a as $k => $v) {
            if (!array_key_exists($k, $b) || !$this->deepEqual($v, $b[$k])) {
                return false;
            }
        }

        return true;
    }
}
