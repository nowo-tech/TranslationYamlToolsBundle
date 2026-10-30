<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Tests\Unit\Ui;

use Nowo\TranslationYamlToolsBundle\Tests\Support\WebUiPageFixtures;
use Nowo\TranslationYamlToolsBundle\Ui\TranslationOverridePageFactory;
use Nowo\TranslationYamlToolsBundle\Ui\WebUiPages;
use Nowo\UiKitBundle\View\Column;
use Nowo\UiKitBundle\View\Confirm;
use Nowo\UiKitBundle\View\FormPage;
use Nowo\UiKitBundle\View\Label;
use Nowo\UiKitBundle\View\ListPage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function array_keys;
use function array_map;

#[CoversClass(TranslationOverridePageFactory::class)]
final class TranslationOverridePageFactoryTest extends TestCase
{
    public function testTextsListHasFiltersEditRowActionsAndPagination(): void
    {
        $context = WebUiPageFixtures::overridesIndex();
        $page    = $context['page'];
        self::assertInstanceOf(ListPage::class, $page);

        self::assertSame('overrides.title', $this->key($page->header->title));
        self::assertSame($context['filter_form'], $page->filters);
        self::assertSame(['q', 'group'], array_keys($page->filterFields));
        self::assertSame('overrides.search_placeholder', $this->key($page->filterFields['q']->placeholder));
        self::assertSame('filter', $page->filtersSubmit?->kind);
        self::assertNull($page->filtersClearHref, 'Nothing to clear without a query or group.');
        self::assertSame(['key', 'text', 'overridden'], array_map(static fn (Column $c): string => $c->key, $page->columns));
        self::assertSame(['%locale%' => 'EN'], $page->columns[1]->label instanceof Label ? $page->columns[1]->label->params : null);
        $actions = $page->rowActions($page->rows[1]);
        self::assertSame('edit', $actions[0]->kind);
        self::assertSame('/nowo-translation-yaml-tools-overrides-edit?key=ShopBundle%3Aloader.loading', $actions[0]->href);
        self::assertSame([], $page->rowActions(['key' => 'unknown', 'text' => '', 'overridden' => []]));
        self::assertNotNull($page->pagination);

        $empty = WebUiPageFixtures::overridesIndex(true)['page'];
        self::assertInstanceOf(ListPage::class, $empty);
        self::assertSame('/nowo-translation-yaml-tools-overrides-index', $empty->filtersClearHref);
        self::assertSame('overrides.empty', $this->key($empty->empty?->message));
    }

    public function testEditPageHasTheRestoreActionOnlyWhenOverridden(): void
    {
        $context = WebUiPageFixtures::overridesEdit();
        $page    = $context['page'];
        self::assertInstanceOf(FormPage::class, $page);

        self::assertSame($context['form'], $page->form);
        self::assertSame('/nowo-translation-yaml-tools-overrides-index?group=site.topline', $page->cancelHref);
        self::assertSame('/nowo-translation-yaml-tools-overrides-index?group=site.topline', $page->header->back?->href);
        self::assertSame([WebUiPages::DEFAULT_FORM_THEME], $page->formThemes);
        self::assertSame(['data-testid' => 'tyt-overrides-form', 'novalidate' => 'novalidate'], $page->formAttributes);
        self::assertNotNull($page->deleteAction);
        self::assertSame('restore', $page->deleteAction->kind);
        self::assertSame('POST', $page->deleteAction->method);
        self::assertSame($context['reset_form'], $page->deleteAction->form);
        self::assertInstanceOf(Confirm::class, $page->deleteAction->confirm);
        self::assertSame(['%locales%' => 'EN'], $page->deleteAction->confirm->message instanceof Label ? $page->deleteAction->confirm->message->params : null);
        self::assertSame(['%locales%' => 'EN'], $page->dangerZoneIntro instanceof Label ? $page->dangerZoneIntro->params : null);

        $shipped = WebUiPageFixtures::overridesEdit(false)['page'];
        self::assertInstanceOf(FormPage::class, $shipped);
        self::assertNull($shipped->deleteAction);
        self::assertNull($shipped->dangerZoneIntro);
    }

    public function testTemplatesAndContextComeFromThePages(): void
    {
        $factory = new TranslationOverridePageFactory(WebUiPageFixtures::urls(), new WebUiPages(WebUiPages::OVERRIDES, null, ['edit' => 'host/edit.html.twig']));

        self::assertSame('host/edit.html.twig', $factory->template('edit'));
        self::assertSame(WebUiPages::TEMPLATES[WebUiPages::OVERRIDES]['index'], $factory->template('index'));
        self::assertSame([WebUiPages::LEGACY_LAYOUT_VARIABLE => null], $factory->context([]));
        self::assertSame(WebUiPages::TEMPLATES[WebUiPages::OVERRIDES]['edit'], (new TranslationOverridePageFactory(WebUiPageFixtures::urls()))->template('edit'));
    }

    private function key(mixed $label): ?string
    {
        return $label instanceof Label ? $label->key : null;
    }
}
