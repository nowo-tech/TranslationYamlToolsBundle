<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Tests\Unit\TextOverride;

use ArrayObject;
use InvalidArgumentException;
use Nowo\TranslationYamlToolsBundle\TextOverride\OverridingTranslator;
use Nowo\TranslationYamlToolsBundle\TextOverride\TranslationOverrideProviderInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Formatter\MessageFormatter;
use Symfony\Component\Translation\Loader\ArrayLoader;
use Symfony\Component\Translation\MessageCatalogue;
use Symfony\Component\Translation\MessageCatalogueInterface;
use Symfony\Component\Translation\Translator;
use Symfony\Component\Translation\TranslatorBagInterface;
use Symfony\Contracts\Translation\LocaleAwareInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(OverridingTranslator::class)]
final class OverridingTranslatorTest extends TestCase
{
    public function testOverrideWinsForExactLocaleDomainAndKey(): void
    {
        $translator = $this->translator(['es' => ['messages' => ['site.hello' => 'Hola %name% (editado)']]]);

        self::assertSame('Hola Ana (editado)', $translator->trans('site.hello', ['%name%' => 'Ana'], null, 'es'));
        self::assertSame('Hello Ana', $translator->trans('site.hello', ['%name%' => 'Ana'], 'messages', 'en'), 'Other locales keep the catalogue.');
        self::assertSame('Otro dominio', $translator->trans('site.hello', [], 'forms', 'es'));
        self::assertSame('', $translator->trans(null));
    }

    public function testUsesCurrentLocaleAndPluralFormatting(): void
    {
        $translator = $this->translator(['es' => ['messages' => ['site.items' => '{1} un elemento|]1,Inf[ %count% elementos']]]);
        $translator->setLocale('es');

        self::assertSame('es', $translator->getLocale());
        self::assertSame('3 elementos', $translator->trans('site.items', ['%count%' => 3]));
    }

    public function testProviderClosureIsResolvedLazily(): void
    {
        $calls      = new ArrayObject();
        $provider   = $this->provider(['en' => ['messages' => ['site.hello' => 'Hi']]]);
        $translator = new OverridingTranslator($this->inner(), static function () use ($calls, $provider): TranslationOverrideProviderInterface {
            $calls->append(true);

            return $provider;
        }, new MessageFormatter());

        self::assertCount(0, $calls);
        self::assertSame('Hi', $translator->trans('site.hello', [], null, 'en'));
        self::assertCount(1, $calls);
    }

    public function testDelegatesCatalogueWarmupAndUnknownMethods(): void
    {
        $translator = $this->translator(['es' => ['messages' => ['site.hello' => 'Editado']]]);

        self::assertSame('Hola %name%', $translator->getCatalogue('es')->get('site.hello'), 'Catalogue keeps the shipped text.');
        self::assertNotEmpty($translator->getCatalogues());
        self::assertSame(['es'], $translator->getFallbackLocales());
        self::assertSame([], $translator->warmUp(sys_get_temp_dir()));
        self::assertSame(['es'], $translator->__call('getFallbackLocales', []));

        $bare = new OverridingTranslator($this->bareInner(), $this->provider([]), new MessageFormatter());
        self::assertSame([], $bare->warmUp(sys_get_temp_dir()));
        self::assertSame([], $bare->getFallbackLocales());
    }

    public function testRejectsTranslatorWithoutBagOrLocale(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new OverridingTranslator($this->createStub(TranslatorInterface::class), $this->provider([]), new MessageFormatter());
    }

    /**
     * @param array<string, array<string, array<string, string>>> $map
     */
    private function translator(array $map): OverridingTranslator
    {
        return new OverridingTranslator($this->inner(), $this->provider($map), new MessageFormatter());
    }

    private function inner(): Translator
    {
        $inner = new Translator('en');
        $inner->setFallbackLocales(['es']);
        $inner->addLoader('array', new ArrayLoader());
        $inner->addResource('array', ['site.hello' => 'Hello %name%'], 'en');
        $inner->addResource('array', ['site.hello' => 'Hola %name%'], 'es');
        $inner->addResource('array', ['site.hello' => 'Otro dominio'], 'es', 'forms');

        return $inner;
    }

    /**
     * @param array<string, array<string, array<string, string>>> $map
     */
    private function provider(array $map): TranslationOverrideProviderInterface
    {
        return new class($map) implements TranslationOverrideProviderInterface {
            /**
             * @param array<string, array<string, array<string, string>>> $map
             */
            public function __construct(private readonly array $map)
            {
            }

            public function get(string $locale, string $domain, string $key): ?string
            {
                return $this->map[$locale][$domain][$key] ?? null;
            }

            public function all(): array
            {
                return $this->map;
            }
        };
    }

    private function bareInner(): TranslatorInterface&TranslatorBagInterface&LocaleAwareInterface
    {
        return new class implements TranslatorInterface, TranslatorBagInterface, LocaleAwareInterface {
            /**
             * @param array<array-key, mixed> $parameters
             */
            public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
            {
                return $id;
            }

            public function getCatalogue(?string $locale = null): MessageCatalogueInterface
            {
                return new MessageCatalogue('en');
            }

            public function getCatalogues(): array
            {
                return [];
            }

            public function setLocale(string $locale): void
            {
            }

            public function getLocale(): string
            {
                return 'en';
            }
        };
    }
}
