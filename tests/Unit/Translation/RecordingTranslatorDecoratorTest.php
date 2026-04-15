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
        new RecordingTranslatorDecorator($inner, $rec, true, true, $builder);
    }

    public function testTransRecordsWhenKeyMissingAndSkipsWhenDefined(): void
    {
        $catalogue = new MessageCatalogue('en', ['messages' => ['present' => 'ok']]);

        $inner = new class implements TranslatorInterface, \Symfony\Component\Translation\TranslatorBagInterface, LocaleAwareInterface {
            private string $locale = 'en';

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

        $d = new RecordingTranslatorDecorator($inner, $recorder, false, false, $builder);
        self::assertSame('missing-t', $d->trans('missing', [], 'messages', 'en'));
        $builder2 = $this->createMock(MissingTranslationLogCallSiteBuilder::class);
        $builder2->expects(self::never())->method('buildContext');
        $recorder = $this->createMock(MissingTranslationRecorderInterface::class);
        $recorder->expects(self::never())->method('record');
        $d2 = new RecordingTranslatorDecorator($inner, $recorder, false, false, $builder2);
        self::assertSame('present-t', $d2->trans('present', [], 'messages', 'en'));
    }

    public function testDelegatesLocaleCatalogueAndFallback(): void
    {
        $inner = new class implements TranslatorInterface, \Symfony\Component\Translation\TranslatorBagInterface, LocaleAwareInterface {
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

            public function getFallbackLocales(): array
            {
                return ['en'];
            }
        };

        $builder = $this->createMock(MissingTranslationLogCallSiteBuilder::class);
        $builder->expects(self::never())->method('buildContext');
        $rec = $this->createMock(MissingTranslationRecorderInterface::class);
        $d   = new RecordingTranslatorDecorator($inner, $rec, false, false, $builder);

        self::assertSame('de', $d->getLocale());
        $d->setLocale('fr');
        self::assertInstanceOf(\Symfony\Component\Translation\MessageCatalogueInterface::class, $d->getCatalogue('de'));
        self::assertCount(1, $d->getCatalogues());
        self::assertSame(['en'], $d->getFallbackLocales());
    }

    public function testTransPassesRequestContextFromBuilderToRecorder(): void
    {
        $catalogue = new MessageCatalogue('en', ['messages' => []]);

        $inner = new class($catalogue) implements TranslatorInterface, \Symfony\Component\Translation\TranslatorBagInterface, LocaleAwareInterface {
            public function __construct(private readonly MessageCatalogue $catalogue)
            {
            }

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

        $d = new RecordingTranslatorDecorator($inner, $recorder, true, true, $builder);
        self::assertSame('ghost', $d->trans('ghost', [], 'messages', 'en'));
    }
}
