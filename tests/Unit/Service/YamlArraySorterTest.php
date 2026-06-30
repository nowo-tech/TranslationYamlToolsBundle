<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Tests\Unit\Service;

use Nowo\TranslationYamlToolsBundle\Service\YamlArraySorter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

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
        self::assertSame($list, $sorter->sortAssociativeRecursive($list)); // @phpstan-ignore argument.type (indexed list passthrough)
    }

    public function testItSortsEmptyAssociativeArray(): void
    {
        $sorter = new YamlArraySorter();
        self::assertSame([], $sorter->sortAssociativeRecursive([]));
    }

    public function testIsRecursivelySortedTrueWhenAlreadySorted(): void
    {
        $sorter = new YamlArraySorter();
        $data   = [
            'a' => ['b' => 1, 'c' => 2],
            'z' => 3,
        ];
        self::assertTrue($sorter->isRecursivelySorted($data));
    }

    public function testIsRecursivelySortedFalseWhenTopLevelOutOfOrder(): void
    {
        $sorter = new YamlArraySorter();
        $data   = [
            'z' => 1,
            'a' => 2,
        ];
        self::assertFalse($sorter->isRecursivelySorted($data));
    }

    public function testIsRecursivelySortedFalseWhenNestedOutOfOrder(): void
    {
        $sorter = new YamlArraySorter();
        $data   = [
            'p' => [
                'z' => 1,
                'a' => 2,
            ],
        ];
        self::assertFalse($sorter->isRecursivelySorted($data));
    }

    public function testDeepEqualEdgeCasesViaReflection(): void
    {
        $sorter = new YamlArraySorter();
        $m      = new ReflectionMethod(YamlArraySorter::class, 'deepEqual');
        $m->setAccessible(true);

        self::assertFalse($m->invoke($sorter, 1, [1]));
        self::assertFalse($m->invoke($sorter, [1], 1));
        self::assertFalse($m->invoke($sorter, 1, 2));
        self::assertTrue($m->invoke($sorter, 1, 1));

        self::assertFalse($m->invoke($sorter, [1, 2], [1, 2, 3]));
        self::assertFalse($m->invoke($sorter, [1, 2], [1, 9]));
        self::assertFalse($m->invoke($sorter, [1, 2], [2, 1]));
        self::assertTrue($m->invoke($sorter, [1, 2], [1, 2]));

        self::assertFalse($m->invoke($sorter, ['a' => 1], [0 => 1]));
        self::assertFalse($m->invoke($sorter, ['a' => 1], ['b' => 1]));
        self::assertFalse($m->invoke($sorter, ['a' => 1], ['a' => 2]));
        self::assertTrue($m->invoke($sorter, ['a' => 1], ['a' => 1]));
    }
}
