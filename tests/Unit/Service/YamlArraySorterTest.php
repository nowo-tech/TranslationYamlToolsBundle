<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Tests\Unit\Service;

use Nowo\TranslationYamlToolsBundle\Service\YamlArraySorter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(YamlArraySorter::class)]
final class YamlArraySorterTest extends TestCase
{
    public function testItSortsKeysRecursively(): void
    {
        $sorter = new YamlArraySorter();
        $data   = [
            'z' => 1,
            'a' => [
                'b' => 2,
                'a' => 3,
            ],
        ];

        $sorted = $sorter->sortAssociativeRecursive($data);
        self::assertSame(['a', 'z'], array_keys($sorted));
        self::assertSame(['a', 'b'], array_keys($sorted['a']));
    }

    public function testItLeavesIndexedListsUnchanged(): void
    {
        $sorter = new YamlArraySorter();
        $list   = [3, 1, 2];
        self::assertSame($list, $sorter->sortAssociativeRecursive($list));
    }

    public function testItSortsEmptyAssociativeArray(): void
    {
        $sorter = new YamlArraySorter();
        self::assertSame([], $sorter->sortAssociativeRecursive([]));
    }
}
