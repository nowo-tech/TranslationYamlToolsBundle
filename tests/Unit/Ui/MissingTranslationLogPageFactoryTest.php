<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Tests\Unit\Ui;

use Nowo\TranslationYamlToolsBundle\Entity\MissingTranslationLogStatus;
use Nowo\TranslationYamlToolsBundle\Tests\Support\WebUiPageFixtures;
use Nowo\TranslationYamlToolsBundle\Ui\MissingTranslationLogPageFactory;
use Nowo\TranslationYamlToolsBundle\Ui\WebUiPages;
use Nowo\UiKitBundle\View\AreaNav;
use Nowo\UiKitBundle\View\AreaNavItem;
use Nowo\UiKitBundle\View\Column;
use Nowo\UiKitBundle\View\Confirm;
use Nowo\UiKitBundle\View\Label;
use Nowo\UiKitBundle\View\ListPage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function array_map;

#[CoversClass(MissingTranslationLogPageFactory::class)]
final class MissingTranslationLogPageFactoryTest extends TestCase
{
    public function testPendingListHasStatusTabsClearActionsAndMarkAddedRowActions(): void
    {
        $context = WebUiPageFixtures::missingLogIndex();
        $page    = $context['page'];
        self::assertInstanceOf(ListPage::class, $page);

        self::assertSame(WebUiPages::BUNDLE, $page->bundle);
        self::assertNull($page->layout);
        self::assertSame('missing_log.title', $this->key($page->header->title));
        self::assertInstanceOf(AreaNav::class, $page->header->tabs);
        self::assertSame(
            ['/nowo-translation-yaml-tools-missing-log-index?status=pending', '/nowo-translation-yaml-tools-missing-log-index?status=added', '/nowo-translation-yaml-tools-missing-log-index?status=validated'],
            array_map(static fn (AreaNavItem $item): string => $item->href, $page->header->tabs->items),
        );
        self::assertSame('missing_log.status.pending', $this->key($page->header->tabs->active()?->label));

        [$clearStatus, $clearAll] = $page->header->actions;
        self::assertSame('POST', $clearStatus->method);
        self::assertSame($context['clear_status_form'], $clearStatus->form);
        self::assertInstanceOf(Confirm::class, $clearStatus->confirm);
        self::assertSame(['%status%' => 'pending'], $clearStatus->confirm->message instanceof Label ? $clearStatus->confirm->message->params : null);
        self::assertSame('delete', $clearAll->kind);
        self::assertSame($context['clear_form'], $clearAll->form);
        self::assertInstanceOf(Confirm::class, $clearAll->confirm);

        self::assertSame(['id', 'locale', 'domain', 'messageId', 'callSite', 'requestRoute', 'requestMethod', 'requestPath', 'hitCount', 'lastSeenAt'], array_map(static fn (Column $c): string => $c->key, $page->columns));
        self::assertCount(2, $page->rows);
        $actions = $page->rowActions($page->rows[0]);
        self::assertCount(1, $actions);
        self::assertSame('/nowo-translation-yaml-tools-missing-log-mark-added?id=1', $actions[0]->href);
        self::assertSame($context['mark_added_forms'][1], $actions[0]->form);
        self::assertNull($actions[0]->confirm, 'Mark added is not destructive.');
        self::assertNotNull($page->pagination);
        self::assertSame(3, $page->pagination->totalPages);
    }

    public function testOtherStatusesHaveNoRowActionsAndAStatusEmptyState(): void
    {
        $added = WebUiPageFixtures::missingLogIndex(MissingTranslationLogStatus::Added)['page'];
        self::assertInstanceOf(ListPage::class, $added);
        self::assertSame([], $added->rowActions($added->rows[0]));
        self::assertSame('missing_log.status.added', $this->key($added->header->tabs?->active()?->label));

        $empty = WebUiPageFixtures::missingLogIndex(MissingTranslationLogStatus::Validated, true)['page'];
        self::assertInstanceOf(ListPage::class, $empty);
        self::assertSame([], $empty->rows);
        self::assertNotNull($empty->empty);
        self::assertSame(['%status%' => 'validated'], $empty->empty->message instanceof Label ? $empty->empty->message->params : null);
    }

    public function testTemplateAndContextComeFromThePages(): void
    {
        $factory = new MissingTranslationLogPageFactory(WebUiPageFixtures::urls(), new WebUiPages(WebUiPages::MISSING_LOG, 'canonical.html.twig', ['index' => 'host/log.html.twig']));

        self::assertSame('host/log.html.twig', $factory->template());
        self::assertSame(['a' => 1, WebUiPages::LEGACY_LAYOUT_VARIABLE => null], $factory->context(['a' => 1]));
        self::assertSame(WebUiPages::TEMPLATES[WebUiPages::MISSING_LOG]['index'], (new MissingTranslationLogPageFactory(WebUiPageFixtures::urls()))->template());
    }

    private function key(mixed $label): ?string
    {
        return $label instanceof Label ? $label->key : null;
    }
}
