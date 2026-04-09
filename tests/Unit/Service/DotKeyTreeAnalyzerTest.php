<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Tests\Unit\Service;

use Nowo\TranslationYamlToolsBundle\Service\DotKeyTreeAnalyzer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DotKeyTreeAnalyzer::class)]
final class DotKeyTreeAnalyzerTest extends TestCase
{
    public function testItDetectsLeafAndPrefixConflict(): void
    {
        $analyzer = new DotKeyTreeAnalyzer();
        $flat     = [
            'a'   => 'leaf',
            'a.b' => 'nested',
        ];

        $conflict = $analyzer->treeConversionConflict($flat);
        self::assertNotNull($conflict);
        self::assertStringContainsString('a', $conflict);

        $collected = $analyzer->collectTreeConversionConflicts($flat);
        self::assertCount(1, $collected);
        self::assertSame(DotKeyTreeAnalyzer::CONFLICT_LEAF_AND_PREFIX, $collected[0]['type']);
        self::assertSame('a', $collected[0]['leaf_key']);
        self::assertSame('a.b', $collected[0]['blocked_key']);
    }

    public function testItCollectsMultipleIndependentTreeConflicts(): void
    {
        $analyzer = new DotKeyTreeAnalyzer();
        $flat     = [
            'a'   => 1,
            'a.b' => 2,
            'x'   => 3,
            'x.y' => 4,
        ];

        $collected = $analyzer->collectTreeConversionConflicts($flat);
        self::assertCount(2, $collected);
    }

    public function testItUnflattensWithoutConflict(): void
    {
        $analyzer = new DotKeyTreeAnalyzer();
        $flat     = [
            'app.title'  => 'Hello',
            'app.footer' => 'Bye',
        ];

        self::assertNull($analyzer->treeConversionConflict($flat));
        $tree = $analyzer->unflatten($flat);
        self::assertSame(['Hello', 'Bye'], [$tree['app']['title'], $tree['app']['footer']]);
    }

    public function testItFlattensNestedStructure(): void
    {
        $analyzer = new DotKeyTreeAnalyzer();
        $nested   = [
            'app' => [
                'title' => 'Hello',
            ],
        ];

        $flat = $analyzer->flatten($nested);
        self::assertSame(['app.title' => 'Hello'], $flat);
    }

    public function testItFlattensEmptyAssociativeChildAsEmptyLeaf(): void
    {
        $analyzer = new DotKeyTreeAnalyzer();
        $flat     = $analyzer->flatten(['wrap' => []]);
        self::assertSame(['wrap' => []], $flat);
    }

    public function testItKeepsListValuesAsSingleLeaf(): void
    {
        $analyzer = new DotKeyTreeAnalyzer();
        $flat     = $analyzer->flatten(['items' => [1, 2, 3]]);
        self::assertSame(['items' => [1, 2, 3]], $flat);
    }

    public function testItSkipsUnflattenSegmentsWithEmptyLastPart(): void
    {
        $analyzer = new DotKeyTreeAnalyzer();
        $tree     = $analyzer->unflatten(['ok' => 1, 'trail.' => 2]);
        self::assertSame(['ok' => 1], $tree);
    }

    public function testItReportsNoConflictForSingleSegmentKeysOnly(): void
    {
        $analyzer = new DotKeyTreeAnalyzer();
        self::assertNull($analyzer->treeConversionConflict(['a' => 1, 'b' => 2]));
    }

    public function testItCountsFlattenedLeaves(): void
    {
        $analyzer = new DotKeyTreeAnalyzer();
        self::assertSame(0, $analyzer->countFlattenedLeaves([]));
        self::assertSame(2, $analyzer->countFlattenedLeaves(['a' => 1, 'b' => ['c' => 2]]));
    }

    public function testItVerifiesPreservationAfterUnflatten(): void
    {
        $analyzer = new DotKeyTreeAnalyzer();
        $flat     = ['app.title' => 'Hi', 'app.body' => 'There'];
        $tree     = $analyzer->unflatten($flat);
        self::assertNull($analyzer->verifyFlattenedLeavesPreserved($flat, $tree));
    }

    public function testItDetectsLeafCountMismatchOnVerify(): void
    {
        $analyzer = new DotKeyTreeAnalyzer();
        $msg      = $analyzer->verifyFlattenedLeavesPreserved(['a' => 1], ['a' => 1, 'b' => 2]);
        self::assertNotNull($msg);
        self::assertStringContainsString('count mismatch', $msg);
    }

    public function testItDetectsMissingLeafKeyOnVerify(): void
    {
        $analyzer = new DotKeyTreeAnalyzer();
        $msg      = $analyzer->verifyFlattenedLeavesPreserved(
            ['a' => 1, 'b' => 2, 'c' => 3],
            ['a' => 1, 'b' => 2, 'd' => 3],
        );
        self::assertNotNull($msg);
        self::assertStringContainsString('Missing leaf key', $msg);
    }

    public function testItDetectsValueMismatchOnVerify(): void
    {
        $analyzer = new DotKeyTreeAnalyzer();
        $msg      = $analyzer->verifyFlattenedLeavesPreserved(['k' => 'old'], ['k' => 'new']);
        self::assertNotNull($msg);
        self::assertStringContainsString('Value mismatch', $msg);
    }

    public function testUniqueConflictTuplesSkipsDuplicates(): void
    {
        $analyzer = new DotKeyTreeAnalyzer();
        $method   = new \ReflectionMethod(DotKeyTreeAnalyzer::class, 'uniqueConflictTuples');
        $method->setAccessible(true);
        $dupes = [
            [
                'type'        => DotKeyTreeAnalyzer::CONFLICT_LEAF_AND_PREFIX,
                'leaf_key'    => 'a',
                'blocked_key' => 'a.b',
            ],
            [
                'type'        => DotKeyTreeAnalyzer::CONFLICT_LEAF_AND_PREFIX,
                'leaf_key'    => 'a',
                'blocked_key' => 'a.b',
            ],
        ];
        $out = $method->invoke($analyzer, $dupes);
        self::assertCount(1, $out);
    }
}
