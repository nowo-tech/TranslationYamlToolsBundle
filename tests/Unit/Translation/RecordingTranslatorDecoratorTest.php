<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Tests\Unit\Translation;

use InvalidArgumentException;
use Nowo\TranslationYamlToolsBundle\MissingTranslationLog\MissingTranslationRecorderInterface;
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
        new RecordingTranslatorDecorator($inner, $rec);
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

        $recorder = $this->createMock(MissingTranslationRecorderInterface::class);
        $recorder->expects(self::once())->method('record')->with('missing', 'messages', 'en', self::anything());

        $d = new RecordingTranslatorDecorator($inner, $recorder, false);
        self::assertSame('missing-t', $d->trans('missing', [], 'messages', 'en'));
        $recorder = $this->createMock(MissingTranslationRecorderInterface::class);
        $recorder->expects(self::never())->method('record');
        $d2 = new RecordingTranslatorDecorator($inner, $recorder, false);
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

        $rec = $this->createMock(MissingTranslationRecorderInterface::class);
        $d   = new RecordingTranslatorDecorator($inner, $rec, false);

        self::assertSame('de', $d->getLocale());
        $d->setLocale('fr');
        self::assertInstanceOf(\Symfony\Component\Translation\MessageCatalogueInterface::class, $d->getCatalogue('de'));
        self::assertCount(1, $d->getCatalogues());
        self::assertSame(['en'], $d->getFallbackLocales());
    }
}
