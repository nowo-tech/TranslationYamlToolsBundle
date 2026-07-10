<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Tests\Unit\Translation;

use Nowo\TranslationYamlToolsBundle\Translation\MissingTranslationLogCallSiteBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

use function strlen;

#[CoversClass(MissingTranslationLogCallSiteBuilder::class)]
final class MissingTranslationLogCallSiteBuilderTest extends TestCase
{
    public function testBuildContextReturnsAllNullWhenBothDisabled(): void
    {
        $builder = new MissingTranslationLogCallSiteBuilder(new RequestStack());
        $ctx     = $builder->buildContext(false, false);
        self::assertNull($ctx->callSite);
        self::assertNull($ctx->requestRoute);
        self::assertNull($ctx->requestMethod);
        self::assertNull($ctx->requestPath);
    }

    public function testBuildContextRequestOnlySetsRouteMethodPath(): void
    {
        $request = Request::create('/hello/world', 'PATCH');
        $request->attributes->set('_route', 'api_demo');
        $stack = new RequestStack([$request]);

        $builder = new MissingTranslationLogCallSiteBuilder($stack);
        $ctx     = $builder->buildContext(false, true);
        self::assertNull($ctx->callSite);
        self::assertSame('api_demo', $ctx->requestRoute);
        self::assertSame('PATCH', $ctx->requestMethod);
        self::assertSame('/hello/world', $ctx->requestPath);
    }

    public function testBuildContextRequestOmitsEmptyRoute(): void
    {
        $stack = new RequestStack([Request::create('/only-path', 'GET')]);

        $builder = new MissingTranslationLogCallSiteBuilder($stack);
        $ctx     = $builder->buildContext(false, true);
        self::assertNull($ctx->requestRoute);
        self::assertSame('GET', $ctx->requestMethod);
        self::assertSame('/only-path', $ctx->requestPath);
    }

    public function testBuildContextTruncatesLongPath(): void
    {
        $longPath = '/' . str_repeat('x', 3000);
        $request  = Request::create($longPath, 'GET');
        $request->attributes->set('_route', 'x');
        $stack = new RequestStack([$request]);

        $builder = new MissingTranslationLogCallSiteBuilder($stack);
        $ctx     = $builder->buildContext(false, true);
        self::assertNotNull($ctx->requestPath);
        self::assertLessThanOrEqual(2048, strlen($ctx->requestPath));
        self::assertStringEndsWith('...', $ctx->requestPath);
    }

    public function testBuildContextBacktraceOnlySetsCallSite(): void
    {
        $builder = new MissingTranslationLogCallSiteBuilder(new RequestStack());
        $ctx     = $builder->buildContext(true, false);
        self::assertIsString($ctx->callSite);
        self::assertNotSame('', $ctx->callSite);
        self::assertNull($ctx->requestRoute);
        self::assertNull($ctx->requestMethod);
        self::assertNull($ctx->requestPath);
    }
}
