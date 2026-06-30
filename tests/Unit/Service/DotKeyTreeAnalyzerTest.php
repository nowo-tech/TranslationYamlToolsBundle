<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Tests\Unit\Service;

use Nowo\TranslationYamlToolsBundle\Service\DotKeyTreeAnalyzer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

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
        $method   = new ReflectionMethod(DotKeyTreeAnalyzer::class, 'uniqueConflictTuples');
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

    public function testDisambiguateLeafPrefixConflictsRenamesBlockingLeaf(): void
    {
        $analyzer = new DotKeyTreeAnalyzer();
        $flat     = ['a' => 'leaf', 'a.b' => 'nested'];
        $result   = $analyzer->disambiguateLeafPrefixConflicts($flat, 'index');
        self::assertArrayNotHasKey('error', $result);
        self::assertArrayHasKey('flat', $result);
        /** @var array<string, mixed> $flatResult */
        $flatResult = $result['flat'];
        self::assertSame('leaf', $flatResult['a.index']);
        self::assertSame('nested', $flatResult['a.b']);
        self::assertSame([['from' => 'a', 'to' => 'a.index']], $result['renames']);
        self::assertNull($analyzer->treeConversionConflict($flatResult));
    }

    public function testDisambiguateLeafPrefixConflictsHandlesChainedConflicts(): void
    {
        $analyzer = new DotKeyTreeAnalyzer();
        $flat     = ['a' => 1, 'a.b' => 2, 'a.b.c' => 3];
        $result   = $analyzer->disambiguateLeafPrefixConflicts($flat, 'index');
        self::assertArrayNotHasKey('error', $result);
        self::assertArrayHasKey('flat', $result);
        /** @var array<string, mixed> $flatResult */
        $flatResult = $result['flat'];
        self::assertNull($analyzer->treeConversionConflict($flatResult));
        self::assertSame(1, $flatResult['a.index']);
        self::assertSame(2, $flatResult['a.b.index']);
        self::assertSame(3, $flatResult['a.b.c']);
    }

    public function testDisambiguateLeafPrefixConflictsFailsWhenTargetKeyExists(): void
    {
        $analyzer = new DotKeyTreeAnalyzer();
        $flat     = ['a' => 'one', 'a.index' => 'two', 'a.b' => 'three'];
        $result   = $analyzer->disambiguateLeafPrefixConflicts($flat, 'index');
        self::assertArrayHasKey('error', $result);
        self::assertStringContainsString('already exists', (string) $result['error']);
    }

    public function testDisambiguateLeafPrefixConflictsRejectsInvalidSuffix(): void
    {
        $analyzer = new DotKeyTreeAnalyzer();
        $result   = $analyzer->disambiguateLeafPrefixConflicts(['a' => 1, 'a.b' => 2], 'bad.suffix');
        self::assertArrayHasKey('error', $result);
    }
}
