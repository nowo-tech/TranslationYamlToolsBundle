<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Tests\Unit\Translation;

use Nowo\TranslationYamlToolsBundle\Translation\TranslationCallSiteResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

#[CoversClass(TranslationCallSiteResolver::class)]
final class TranslationCallSiteResolverTest extends TestCase
{
    public function testShouldSkipPathDetectsBundleInternalsAndSymfonyTranslation(): void
    {
        $m = new ReflectionMethod(TranslationCallSiteResolver::class, 'shouldSkipPath');
        $m->setAccessible(true);

        self::assertTrue($m->invoke(null, '/any/path/TranslationCallSiteResolver.php'));
        self::assertTrue($m->invoke(null, '/any/path/MissingTranslationLogCallSiteBuilder.php'));
        self::assertTrue($m->invoke(null, '/any/path/RecordingTranslatorDecorator.php'));
        self::assertTrue($m->invoke(null, '/any/path/DoctrineMissingTranslationRecorder.php'));
        self::assertTrue($m->invoke(null, '/vendor/symfony/translation/Translator.php'));
        self::assertTrue($m->invoke(null, '/vendor/symfony/twig-bridge/Extension/TranslationExtension.php'));
        self::assertTrue($m->invoke(null, '/vendor/symfony/contracts/Translation/TranslatorTrait.php'));
        self::assertFalse($m->invoke(null, '/app/src/Controller/HomeController.php'));
    }

    public function testPickCallSiteFromTraceReturnsNullForEmptyOrOnlySkippedFrames(): void
    {
        $m = new ReflectionMethod(TranslationCallSiteResolver::class, 'pickCallSiteFromTrace');
        $m->setAccessible(true);

        self::assertNull($m->invoke(null, []));
        self::assertNull($m->invoke(null, [['file' => '']]));
        self::assertNull($m->invoke(null, [
            ['file' => '/any/path/RecordingTranslatorDecorator.php', 'line' => 1],
            ['file' => '/vendor/symfony/translation/Translator.php', 'line' => 2],
        ]));
    }

    public function testPickCallSiteFromTraceSkipsEmptyFileThenUsesFirstUsableFrame(): void
    {
        $m = new ReflectionMethod(TranslationCallSiteResolver::class, 'pickCallSiteFromTrace');
        $m->setAccessible(true);

        $picked = $m->invoke(null, [
            ['line' => 9],
            ['file' => '', 'line' => 1],
            ['file' => '/app/MyController.php', 'line' => 42],
        ]);
        self::assertSame('/app/MyController.php:42', $picked);
    }

    public function testPickCallSiteFromTraceTruncatesLongSite(): void
    {
        $m = new ReflectionMethod(TranslationCallSiteResolver::class, 'pickCallSiteFromTrace');
        $m->setAccessible(true);

        $longPath = str_repeat('a', 1020) . '.php';
        $picked   = $m->invoke(null, [['file' => $longPath, 'line' => 999]]);
        self::assertSame(1024, strlen((string) $picked));
        self::assertStringEndsWith('...', (string) $picked);
    }

    public function testResolveReturnsCallSiteForCurrentStack(): void
    {
        $r = TranslationCallSiteResolver::resolve();
        self::assertNotNull($r);
        self::assertLessThanOrEqual(1024, strlen((string) $r));
        self::assertStringContainsString('TranslationCallSiteResolverTest.php', (string) $r);
    }

    public function testResolveTruncatesWhenSiteExceeds1024Characters(): void
    {
        $deepDir = sys_get_temp_dir() . '/' . str_repeat('abcdefghij/', 110);
        if (!@mkdir($deepDir, 0777, true) && !is_dir($deepDir)) {
            self::markTestSkipped('Could not create deep temp directory for long-path test');
        }

        $runner   = $deepDir . 'invoke_resolver.php';
        $autoload = dirname(__DIR__, 3) . '/vendor/autoload.php';
        $runnerPhp = "<?php\nrequire " . var_export($autoload, true) . ";\n"
            . "echo \\Nowo\\TranslationYamlToolsBundle\\Translation\\TranslationCallSiteResolver::resolve() ?? '';\n";
        file_put_contents($runner, $runnerPhp);

        $cmd = sprintf('%s %s 2>/dev/null', escapeshellarg(PHP_BINARY), escapeshellarg($runner));
        $out = shell_exec($cmd);
        @unlink($runner);

        self::assertIsString($out);
        self::assertGreaterThan(1020, strlen($runner), 'Runner path must exceed resolver truncation threshold');
        self::assertSame(1024, strlen($out));
        self::assertStringEndsWith('...', $out);
    }
}
