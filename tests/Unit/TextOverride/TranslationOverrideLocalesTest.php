<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Tests\Unit\TextOverride;

use Nowo\TranslationYamlToolsBundle\Service\TranslationDefaultLocaleResolver;
use Nowo\TranslationYamlToolsBundle\TextOverride\TranslationOverrideLocales;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;

#[CoversClass(TranslationOverrideLocales::class)]
final class TranslationOverrideLocalesTest extends TestCase
{
    public function testConfiguredLocalesWinAndDefaultComesFirst(): void
    {
        $locales = new TranslationOverrideLocales($this->resolver('es'), ['en', 'fr', 'en', ''], ['de']);

        self::assertSame('es', $locales->default());
        self::assertSame(['es', 'en', 'fr'], $locales->all());
    }

    public function testFallsBackToEnabledLocalesThenDefaultOnly(): void
    {
        self::assertSame(['en', 'es'], (new TranslationOverrideLocales($this->resolver('en'), [], ['es', 'en', 42]))->all());
        self::assertSame(['en'], (new TranslationOverrideLocales($this->resolver('en')))->all());
        self::assertSame(['pt'], (new TranslationOverrideLocales($this->resolver('en'), [], [], 'pt'))->all(), 'Bundle default_locale wins.');
    }

    private function resolver(string $kernelDefault): TranslationDefaultLocaleResolver
    {
        return new TranslationDefaultLocaleResolver(new ParameterBag(['kernel.default_locale' => $kernelDefault]));
    }
}
