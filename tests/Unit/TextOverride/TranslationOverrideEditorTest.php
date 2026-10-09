<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Tests\Unit\TextOverride;

use Nowo\TranslationYamlToolsBundle\Service\TranslationDefaultLocaleResolver;
use Nowo\TranslationYamlToolsBundle\TextOverride\Entity\TranslationOverride;
use Nowo\TranslationYamlToolsBundle\TextOverride\Html\StripTagsTranslationOverrideHtmlSanitizer;
use Nowo\TranslationYamlToolsBundle\TextOverride\Repository\TranslationOverrideRepository;
use Nowo\TranslationYamlToolsBundle\TextOverride\TranslationOverrideEditor;
use Nowo\TranslationYamlToolsBundle\TextOverride\TranslationOverrideLocales;
use Nowo\TranslationYamlToolsBundle\TextOverride\TranslationOverrides;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\Translation\Loader\ArrayLoader;
use Symfony\Component\Translation\Translator;

#[CoversClass(TranslationOverrideEditor::class)]
final class TranslationOverrideEditorTest extends TestCase
{
    public function testShippedAndCurrentTextsPerLocale(): void
    {
        $repository = $this->createStub(TranslationOverrideRepository::class);
        $repository->method('loadMap')->willReturn(['es' => ['messages' => ['site.hello' => 'Hola editado']]]);
        $editor = $this->editor($repository);

        self::assertSame('en', $editor->defaultLocale());
        self::assertSame(['en', 'es', 'fr'], $editor->locales());
        self::assertSame(['en' => 'Hello', 'es' => 'Hola', 'fr' => ''], $editor->shippedTexts('messages', 'site.hello'));
        self::assertSame(['en' => 'Hello', 'es' => 'Hola editado', 'fr' => ''], $editor->currentTexts('messages', 'site.hello'));
        self::assertSame(['es'], $editor->overriddenLocales('messages', 'site.hello'));
    }

    public function testSaveSkipsShippedTextAndMissingLocales(): void
    {
        $repository = $this->createMock(TranslationOverrideRepository::class);
        $repository->method('findOne')->willReturn(null);
        $persisted = [];
        $repository->expects(self::once())->method('persist')->willReturnCallback(static function (TranslationOverride $row) use (&$persisted): void {
            $persisted[] = $row->getLocale() . '=' . $row->getValue() . '@' . $row->getUpdatedBy();
        });
        $repository->expects(self::once())->method('flush');

        $this->editor($repository)->save('messages', 'site.hello', [
            'en' => ' Hello ',        // shipped text → no row
            'es' => '<b>Hola</b> amigos', // sanitized, then stored
            'de' => 'ignored',        // not an editor locale
            // fr missing → untouched
        ], true, 'ana');

        self::assertSame(['es=Hola amigos@ana'], $persisted);
    }

    public function testResetRemovesEveryLocale(): void
    {
        $row        = (new TranslationOverride('es', 'messages', 'site.hello'))->setValue('x');
        $repository = $this->createMock(TranslationOverrideRepository::class);
        $repository->method('findOne')->willReturnCallback(static fn (string $locale): ?TranslationOverride => $locale === 'es' ? $row : null);
        $repository->expects(self::exactly(2))->method('delete')->with($row);
        $repository->expects(self::exactly(2))->method('flush');

        $editor = $this->editor($repository);
        $editor->reset('messages', 'site.hello');
        $editor->reset('messages', 'site.hello', false);
        $editor->save('messages', 'site.hello', [], false);
        $editor->flush();
    }

    private function editor(TranslationOverrideRepository $repository): TranslationOverrideEditor
    {
        $translator = new Translator('en');
        $translator->addLoader('array', new ArrayLoader());
        $translator->addResource('array', ['site.hello' => 'Hello'], 'en');
        $translator->addResource('array', ['site.hello' => 'Hola'], 'es');

        $overrides = new TranslationOverrides($repository, new ArrayAdapter(), new StripTagsTranslationOverrideHtmlSanitizer());
        $locales   = new TranslationOverrideLocales(
            new TranslationDefaultLocaleResolver(new ParameterBag(['kernel.default_locale' => 'en'])),
            ['en', 'es', 'fr'],
        );

        return new TranslationOverrideEditor($overrides, $translator, $locales);
    }
}
