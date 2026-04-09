<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Service;

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
