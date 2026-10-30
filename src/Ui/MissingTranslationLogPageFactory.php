<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Ui;

use Nowo\TranslationYamlToolsBundle\Entity\MissingTranslationLog;
use Nowo\TranslationYamlToolsBundle\Entity\MissingTranslationLogStatus;
use Nowo\TranslationYamlToolsBundle\Form\MissingTranslationLogActionType;
use Nowo\UiKitBundle\View\Action;
use Nowo\UiKitBundle\View\AreaNav;
use Nowo\UiKitBundle\View\AreaNavItem;
use Nowo\UiKitBundle\View\Column;
use Nowo\UiKitBundle\View\Confirm;
use Nowo\UiKitBundle\View\EmptyState;
use Nowo\UiKitBundle\View\ListPage;
use Nowo\UiKitBundle\View\PageHeader;
use Nowo\UiKitBundle\View\Pagination;
use Symfony\Component\Form\FormView;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Builds the UiKit view model of the missing-translation log page (REQ-UI-003): page header (status tabs, Clear
 * current status / Clear all rows behind the UiKit confirm dialog), columns, the Mark added row action, pagination
 * and empty state. Every POST action is a CSRF-only {@see FormView} of
 * {@see MissingTranslationLogActionType}. The controller and the page contract
 * test share it. Stateless: safe in worker mode.
 */
final readonly class MissingTranslationLogPageFactory
{
    /** Route of the list page. */
    public const INDEX_ROUTE = 'nowo_translation_yaml_tools_missing_log_index';

    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
        private WebUiPages $pages = new WebUiPages(WebUiPages::MISSING_LOG),
    ) {
    }

    /**
     * Template of the list page (`missing_translation_log.web_ui.templates.index`, else the bundle template).
     */
    public function template(): string
    {
        return $this->pages->template('index');
    }

    /**
     * Page variables plus the shared ones (legacy layout bridge).
     *
     * @param array<string, mixed> $vars
     *
     * @return array<string, mixed>
     */
    public function context(array $vars): array
    {
        return $this->pages->context($vars);
    }

    /**
     * Missing-translation log rows of one status.
     *
     * @param list<MissingTranslationLog> $rows
     * @param array<int, FormView> $markAddedForms row id => CSRF-only "mark added" form (pending rows)
     */
    public function index(
        array $rows,
        MissingTranslationLogStatus $status,
        Pagination $pagination,
        FormView $clearStatusForm,
        FormView $clearForm,
        array $markAddedForms,
    ): ListPage {
        $statusLabel = WebUiPages::label('missing_log.status.' . $status->value);
        $actions     = [];
        foreach ($rows as $row) {
            $id = (int) $row->getId();
            if (isset($markAddedForms[$id])) {
                $actions[$id] = [new Action(
                    'custom',
                    WebUiPages::label('missing_log.mark_added'),
                    $this->urlGenerator->generate('nowo_translation_yaml_tools_missing_log_mark_added', ['id' => $id]),
                    method: 'POST',
                    icon: 'check2',
                    variant: 'secondary',
                    attributes: ['data-testid' => 'tyt-missing-log-mark-added-' . $id],
                    form: $markAddedForms[$id],
                )];
            }
        }

        $tabs = [];
        foreach (MissingTranslationLogStatus::cases() as $case) {
            $tabs[] = new AreaNavItem(
                WebUiPages::label('missing_log.status.' . $case->value),
                $this->indexUrl($case),
                $case === $status,
                ['data-testid' => 'tyt-missing-log-status-' . $case->value],
            );
        }

        return new ListPage(
            header: new PageHeader(
                WebUiPages::label('missing_log.title'),
                WebUiPages::label('missing_log.intro'),
                [
                    new Action(
                        'custom',
                        WebUiPages::label('missing_log.clear_status.button'),
                        $this->urlGenerator->generate('nowo_translation_yaml_tools_missing_log_clear_status'),
                        method: 'POST',
                        confirm: new Confirm(
                            WebUiPages::label('missing_log.clear_status.confirm_title', ['%status%' => $status->value]),
                            WebUiPages::label('missing_log.clear_status.confirm_message', ['%status%' => $status->value]),
                            WebUiPages::label('missing_log.clear_status.button'),
                        ),
                        icon: 'eraser',
                        variant: 'secondary',
                        attributes: ['data-testid' => 'tyt-missing-log-clear-status'],
                        form: $clearStatusForm,
                    ),
                    new Action(
                        'delete',
                        WebUiPages::label('missing_log.clear_all.button'),
                        $this->urlGenerator->generate('nowo_translation_yaml_tools_missing_log_clear'),
                        method: 'POST',
                        confirm: new Confirm(
                            WebUiPages::label('missing_log.clear_all.confirm_title'),
                            WebUiPages::label('missing_log.clear_all.confirm_message'),
                            WebUiPages::label('missing_log.clear_all.button'),
                        ),
                        variant: 'danger',
                        attributes: ['data-testid' => 'tyt-missing-log-clear-all'],
                        form: $clearForm,
                    ),
                ],
                tabs: new AreaNav($tabs, WebUiPages::label('missing_log.status_tabs')),
            ),
            columns: [
                new Column('id', WebUiPages::label('missing_log.col.id'), priority: 3),
                new Column('locale', WebUiPages::label('missing_log.col.locale')),
                new Column('domain', WebUiPages::label('missing_log.col.domain'), priority: 2),
                new Column('messageId', WebUiPages::label('missing_log.col.message_id'), required: true),
                new Column('callSite', WebUiPages::label('missing_log.col.call_site'), priority: 3),
                new Column('requestRoute', WebUiPages::label('missing_log.col.route'), priority: 3),
                new Column('requestMethod', WebUiPages::label('missing_log.col.method'), priority: 3),
                new Column('requestPath', WebUiPages::label('missing_log.col.path'), priority: 2),
                new Column('hitCount', WebUiPages::label('missing_log.col.hits'), align: 'end'),
                new Column('lastSeenAt', WebUiPages::label('missing_log.col.last_seen'), priority: 2),
            ],
            rows: $rows,
            rowActions: static fn (MissingTranslationLog $row): array => $actions[(int) $row->getId()] ?? [],
            pagination: $pagination,
            empty: new EmptyState(null, WebUiPages::label('missing_log.empty', ['%status%' => $status->value])),
            caption: $statusLabel,
            bundle: WebUiPages::BUNDLE,
            layout: $this->pages->layout(),
            attributes: ['data-testid' => 'tyt-missing-log', 'data-status' => $status->value],
            actionsLabel: WebUiPages::label('missing_log.col.actions'),
            captionVisible: false,
        );
    }

    private function indexUrl(MissingTranslationLogStatus $status): string
    {
        return $this->urlGenerator->generate(self::INDEX_ROUTE, ['status' => $status->value]);
    }
}
