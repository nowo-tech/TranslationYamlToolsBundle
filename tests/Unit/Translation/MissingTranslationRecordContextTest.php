<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Tests\Unit\Translation;

use Nowo\TranslationYamlToolsBundle\Translation\MissingTranslationRecordContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MissingTranslationRecordContext::class)]
final class MissingTranslationRecordContextTest extends TestCase
{
    public function testConstructorStoresContextFields(): void
    {
        $context = new MissingTranslationRecordContext(
            callSite: 'App\\Controller\\DemoController::index',
            requestRoute: 'demo_home',
            requestMethod: 'GET',
            requestPath: '/demo',
        );

        self::assertSame('App\\Controller\\DemoController::index', $context->callSite);
        self::assertSame('demo_home', $context->requestRoute);
        self::assertSame('GET', $context->requestMethod);
        self::assertSame('/demo', $context->requestPath);
    }
}
