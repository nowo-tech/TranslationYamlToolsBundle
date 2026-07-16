<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Tests\Unit\Translation;

use InvalidArgumentException;
use Nowo\TranslationYamlToolsBundle\MissingTranslationLog\MissingTranslationRecorderInterface;
use Nowo\TranslationYamlToolsBundle\Translation\MissingTranslationLogCallSiteBuilder;
use Nowo\TranslationYamlToolsBundle\Translation\MissingTranslationRecordContext;
use Nowo\TranslationYamlToolsBundle\Translation\RecordingTranslatorDecorator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\MessageCatalogue;
use Symfony\Contracts\Translation\LocaleAwareInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(RecordingTranslatorDecorator::class)]
final class RecordingTranslatorDecoratorTest extends TestCase
{
    public function testConstructorRejectsInnerWithoutBagOrLocaleAware(): void
    {
        $inner = $this->createMock(TranslatorInterface::class);
        $rec   = $this->createMock(MissingTranslationRecorderInterface::class);

        $this->expectException(InvalidArgumentException::class);
        $builder = $this->createMock(MissingTranslationLogCallSiteBuilder::class);
        new RecordingTranslatorDecorator($inner, $rec, $builder, true, true);
    }

    public function testTransRecordsWhenKeyMissingAndSkipsWhenDefined(): void
    {
        new MessageCatalogue('en', ['messages' => ['present' => 'ok']]);

        $inner = new class implements TranslatorInterface, \Symfony\Component\Translation\TranslatorBagInterface, LocaleAwareInterface {
            private string $locale = 'en';

            /** @param array<string, bool|float|int|string|null> $parameters */
            public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
            {
                return $id . '-t';
            }

            public function getLocale(): string
            {
                return $this->locale;
            }

            public function setLocale(string $locale): void
            {
                $this->locale = $locale;
            }

            public function getCatalogue(?string $locale = null): \Symfony\Component\Translation\MessageCatalogueInterface
            {
                return new MessageCatalogue('en', ['messages' => ['present' => 'ok']]);
            }

            public function getCatalogues(): array
            {
                return [$this->getCatalogue()];
            }

            /** @return list<string> */
            public function getFallbackLocales(): array
            {
                return [];
            }
        };

        $builder = $this->createMock(MissingTranslationLogCallSiteBuilder::class);
        $builder->expects(self::once())->method('buildContext')->with(false, false)->willReturn(
            new MissingTranslationRecordContext(null, null, null, null),
        );

        $recorder = $this->createMock(MissingTranslationRecorderInterface::class);
        $recorder->expects(self::once())->method('record')->with('missing', 'messages', 'en', null, null, null, null);

        $d = new RecordingTranslatorDecorator($inner, $recorder, $builder, false, false);
        self::assertSame('missing-t', $d->trans('missing', [], 'messages', 'en'));
        $builder2 = $this->createMock(MissingTranslationLogCallSiteBuilder::class);
        $builder2->expects(self::never())->method('buildContext');
        $recorder = $this->createMock(MissingTranslationRecorderInterface::class);
        $recorder->expects(self::never())->method('record');
        $d2 = new RecordingTranslatorDecorator($inner, $recorder, $builder2, false, false);
        self::assertSame('present-t', $d2->trans('present', [], 'messages', 'en'));
    }

    public function testDelegatesLocaleCatalogueAndFallback(): void
    {
        $inner = new class implements TranslatorInterface, \Symfony\Component\Translation\TranslatorBagInterface, LocaleAwareInterface {
            /** @param array<string, bool|float|int|string|null> $parameters */
            public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
            {
                return 'x';
            }

            public function getLocale(): string
            {
                return 'de';
            }

            public function setLocale(string $locale): void
            {
            }

            public function getCatalogue(?string $locale = null): \Symfony\Component\Translation\MessageCatalogueInterface
            {
                return new MessageCatalogue('de');
            }

            public function getCatalogues(): array
            {
                return [new MessageCatalogue('de')];
            }

            /** @return list<string> */
            public function getFallbackLocales(): array
            {
                return ['en'];
            }
        };

        $builder = $this->createMock(MissingTranslationLogCallSiteBuilder::class);
        $builder->expects(self::never())->method('buildContext');
        $rec = $this->createMock(MissingTranslationRecorderInterface::class);
        $d   = new RecordingTranslatorDecorator($inner, $rec, $builder, false, false);

        self::assertSame('de', $d->getLocale());
        $d->setLocale('fr');
        self::assertSame('de', $d->getCatalogue('de')->getLocale());
        self::assertCount(1, $d->getCatalogues());
        self::assertSame(['en'], $d->getFallbackLocales());
    }

    public function testCallForwardsUnknownMethodsToInner(): void
    {
        $inner = new class implements TranslatorInterface, \Symfony\Component\Translation\TranslatorBagInterface, LocaleAwareInterface {
            /** @param array<string, bool|float|int|string|null> $parameters */
            public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
            {
                return 'x';
            }

            public function getLocale(): string
            {
                return 'en';
            }

            public function setLocale(string $locale): void
            {
            }

            public function getCatalogue(?string $locale = null): \Symfony\Component\Translation\MessageCatalogueInterface
            {
                return new MessageCatalogue('en');
            }

            public function getCatalogues(): array
            {
                return [];
            }

            /** @return list<string> */
            public function getFallbackLocales(): array
            {
                return [];
            }

            /** @return string[] */
            public function getFormats(): array
            {
                return ['yaml', 'xlf'];
            }
        };

        $builder = $this->createMock(MissingTranslationLogCallSiteBuilder::class);
        $rec     = $this->createMock(MissingTranslationRecorderInterface::class);
        $d       = new RecordingTranslatorDecorator($inner, $rec, $builder, false, false);

        self::assertSame(['yaml', 'xlf'], $d->getFormats());
    }

    public function testWarmUpDelegatesWhenInnerIsWarmable(): void
    {
        $inner = new class implements TranslatorInterface, \Symfony\Component\Translation\TranslatorBagInterface, LocaleAwareInterface, \Symfony\Component\HttpKernel\CacheWarmer\WarmableInterface {
            /** @param array<string, bool|float|int|string|null> $parameters */
            public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
            {
                return 'x';
            }

            public function getLocale(): string
            {
                return 'en';
            }

            public function setLocale(string $locale): void
            {
            }

            public function getCatalogue(?string $locale = null): \Symfony\Component\Translation\MessageCatalogueInterface
            {
                return new MessageCatalogue('en');
            }

            public function getCatalogues(): array
            {
                return [];
            }

            /** @return list<string> */
            public function getFallbackLocales(): array
            {
                return [];
            }

            public function warmUp(string $cacheDir, ?string $buildDir = null): array
            {
                return ['/warmed.php'];
            }
        };

        $builder = $this->createMock(MissingTranslationLogCallSiteBuilder::class);
        $rec     = $this->createMock(MissingTranslationRecorderInterface::class);
        $d       = new RecordingTranslatorDecorator($inner, $rec, $builder, false, false);

        self::assertSame(['/warmed.php'], $d->warmUp('/cache', '/build'));
    }

    public function testWarmUpReturnsEmptyWhenInnerIsNotWarmable(): void
    {
        $inner = new class implements TranslatorInterface, \Symfony\Component\Translation\TranslatorBagInterface, LocaleAwareInterface {
            /** @param array<string, bool|float|int|string|null> $parameters */
            public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
            {
                return 'x';
            }

            public function getLocale(): string
            {
                return 'en';
            }

            public function setLocale(string $locale): void
            {
            }

            public function getCatalogue(?string $locale = null): \Symfony\Component\Translation\MessageCatalogueInterface
            {
                return new MessageCatalogue('en');
            }

            public function getCatalogues(): array
            {
                return [];
            }

            /** @return list<string> */
            public function getFallbackLocales(): array
            {
                return [];
            }
        };

        $builder = $this->createMock(MissingTranslationLogCallSiteBuilder::class);
        $rec     = $this->createMock(MissingTranslationRecorderInterface::class);
        $d       = new RecordingTranslatorDecorator($inner, $rec, $builder, false, false);

        self::assertSame([], $d->warmUp('/cache'));
    }

    public function testTransPassesRequestContextFromBuilderToRecorder(): void
    {
        $catalogue = new MessageCatalogue('en', ['messages' => []]);

        $inner = new class($catalogue) implements TranslatorInterface, \Symfony\Component\Translation\TranslatorBagInterface, LocaleAwareInterface {
            public function __construct(private readonly MessageCatalogue $catalogue)
            {
            }

            /** @param array<string, bool|float|int|string|null> $parameters */
            public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
            {
                return $id;
            }

            public function getLocale(): string
            {
                return 'en';
            }

            public function setLocale(string $locale): void
            {
            }

            public function getCatalogue(?string $locale = null): \Symfony\Component\Translation\MessageCatalogueInterface
            {
                return $this->catalogue;
            }

            public function getCatalogues(): array
            {
                return [$this->catalogue];
            }

            /** @return list<string> */
            public function getFallbackLocales(): array
            {
                return [];
            }
        };

        $ctx = new MissingTranslationRecordContext('/src/App.php:20', 'demo_route', 'POST', '/api/x');

        $builder = $this->createMock(MissingTranslationLogCallSiteBuilder::class);
        $builder->expects(self::once())->method('buildContext')->with(true, true)->willReturn($ctx);

        $recorder = $this->createMock(MissingTranslationRecorderInterface::class);
        $recorder->expects(self::once())->method('record')->with(
            'ghost',
            'messages',
            'en',
            '/src/App.php:20',
            'demo_route',
            'POST',
            '/api/x',
        );

        $d = new RecordingTranslatorDecorator($inner, $recorder, $builder, true, true);
        self::assertSame('ghost', $d->trans('ghost', [], 'messages', 'en'));
    }

    public function testGetFallbackLocalesReturnsEmptyWhenInnerHasNoMethod(): void
    {
        $inner = new class implements TranslatorInterface, \Symfony\Component\Translation\TranslatorBagInterface, LocaleAwareInterface {
            public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
            {
                return $id;
            }

            public function getLocale(): string
            {
                return 'en';
            }

            public function setLocale(string $locale): void
            {
            }

            public function getCatalogue(?string $locale = null): \Symfony\Component\Translation\MessageCatalogueInterface
            {
                return new MessageCatalogue('en');
            }

            public function getCatalogues(): array
            {
                return [];
            }
        };

        $decorator = new RecordingTranslatorDecorator(
            $inner,
            $this->createMock(MissingTranslationRecorderInterface::class),
            $this->createMock(MissingTranslationLogCallSiteBuilder::class),
            false,
            false,
        );

        self::assertSame([], $decorator->getFallbackLocales());
    }
}
