<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Tests\Unit\Service;

use Nowo\TranslationYamlToolsBundle\Service\TranslationDefaultLocaleResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;

#[CoversClass(TranslationDefaultLocaleResolver::class)]
final class TranslationDefaultLocaleResolverTest extends TestCase
{
    public function testUsesBundleOverrideWhenNonEmpty(): void
    {
        $resolver = new TranslationDefaultLocaleResolver(new ParameterBag([]));
        self::assertSame('de', $resolver->resolve('de'));
    }

    public function testFallsBackToTranslatorDefaultLocale(): void
    {
        $resolver = new TranslationDefaultLocaleResolver(new ParameterBag([
            'translator.default_locale' => 'fr',
        ]));
        self::assertSame('fr', $resolver->resolve(null));
        self::assertSame('fr', $resolver->resolve(''));
    }

    public function testFallsBackToKernelDefaultLocale(): void
    {
        $resolver = new TranslationDefaultLocaleResolver(new ParameterBag([
            'kernel.default_locale' => 'it',
        ]));
        self::assertSame('it', $resolver->resolve(null));
    }

    public function testUltimateFallbackIsEnglish(): void
    {
        $resolver = new TranslationDefaultLocaleResolver(new ParameterBag([]));
        self::assertSame('en', $resolver->resolve(null));
    }
}
