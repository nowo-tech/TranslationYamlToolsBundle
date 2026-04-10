<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Tests\Unit\Translation;

use Nowo\TranslationYamlToolsBundle\Translation\TranslationCallSiteResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TranslationCallSiteResolver::class)]
final class TranslationCallSiteResolverTest extends TestCase
{
    public function testResolvePointsToCallerFile(): void
    {
        $site = $this->delegateResolve();
        self::assertNotNull($site);
        self::assertStringContainsString(__FILE__, $site);
        self::assertStringContainsString(':', $site);
    }

    private function delegateResolve(): ?string
    {
        return TranslationCallSiteResolver::resolve();
    }
}
