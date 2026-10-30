<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Ui;

use Nowo\UiKitBundle\View\Action;
use Nowo\UiKitBundle\View\BackLink;
use Nowo\UiKitBundle\View\Column;
use Nowo\UiKitBundle\View\Confirm;
use Nowo\UiKitBundle\View\EmptyState;
use Nowo\UiKitBundle\View\FilterField;
use Nowo\UiKitBundle\View\FormPage;
use Nowo\UiKitBundle\View\ListPage;
use Nowo\UiKitBundle\View\PageHeader;
use Nowo\UiKitBundle\View\Pagination;
use Symfony\Component\Form\FormView;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use function array_map;
use function implode;
use function strtoupper;

/**
 * Builds the UiKit view models of the text-overrides desk (REQ-UI-003): the texts list (search / group filters,
 * Edit row action, pagination, empty state) and the edit form (one locale tab per text, Save / Cancel, the Restore
 * shipped text action behind the UiKit confirm dialog). The controller and the page contract test share it.
 * Stateless: safe in worker mode.
 */
final readonly class TranslationOverridePageFactory
{
    /** Route of the texts list. */
    public const INDEX_ROUTE = 'nowo_translation_yaml_tools_overrides_index';

    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
        private WebUiPages $pages = new WebUiPages(WebUiPages::OVERRIDES),
    ) {
    }

    /**
     * Template of a page (`overrides.web_ui.templates.<page>`, else the bundle template): `index` or `edit`.
     */
    public function template(string $page): string
    {
        return $this->pages->template($page);
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
     * Editable texts.
     *
     * @param list<array{key: string, text: string, overridden: list<string>}> $rows rows of the current page
     * @param FormView $filters GET search / group form
     */
    public function index(array $rows, FormView $filters, Pagination $pagination, string $defaultLocale, bool $filtered): ListPage
    {
        $edit = [];
        foreach ($rows as $row) {
            $edit[$row['key']] = [Action::edit(
                $this->urlGenerator->generate('nowo_translation_yaml_tools_overrides_edit', ['key' => $row['key']]),
                WebUiPages::label('overrides.edit'),
            )];
        }

        return new ListPage(
            header: new PageHeader(WebUiPages::label('overrides.title'), WebUiPages::label('overrides.intro')),
            columns: [
                new Column('key', WebUiPages::label('overrides.col.key'), required: true),
                new Column('text', WebUiPages::label('overrides.col.text', ['%locale%' => strtoupper($defaultLocale)])),
                new Column('overridden', WebUiPages::label('overrides.col.overridden'), priority: 2),
            ],
            rows: $rows,
            filters: $filters,
            rowActions: static fn (array $row): array => $edit[$row['key']] ?? [],
            pagination: $pagination,
            empty: new EmptyState(null, WebUiPages::label('overrides.empty')),
            filtersClearHref: $filtered ? $this->urlGenerator->generate(self::INDEX_ROUTE) : null,
            bundle: WebUiPages::BUNDLE,
            layout: $this->pages->layout(),
            attributes: ['data-testid' => 'tyt-overrides-index'],
            actionsLabel: WebUiPages::label('overrides.col.actions'),
            actionsLabelVisible: false,
            filterFields: [
                new FilterField('q', WebUiPages::label('overrides.search_placeholder'), 'lg'),
                new FilterField('group', null, 'md'),
            ],
            filtersSubmit: new Action('filter', WebUiPages::label('overrides.filter'), variant: 'primary'),
        );
    }

    /**
     * One editable text (one locale tab per text).
     *
     * @param list<string> $overridden locales with an override (non-empty: the Restore shipped text action)
     */
    public function edit(string $ref, string $group, FormView $form, ?FormView $resetForm, array $overridden): FormPage
    {
        $indexUrl = $this->urlGenerator->generate(self::INDEX_ROUTE, ['group' => $group]);
        $locales  = implode(', ', array_map(strtoupper(...), $overridden));
        $reset    = null;
        if ($resetForm instanceof FormView) {
            $reset = new Action(
                'restore',
                WebUiPages::label('overrides.reset'),
                $this->urlGenerator->generate('nowo_translation_yaml_tools_overrides_reset', ['key' => $ref]),
                method: 'POST',
                confirm: new Confirm(
                    WebUiPages::label('overrides.reset_confirm_title'),
                    WebUiPages::label('overrides.reset_confirm_message', ['%locales%' => $locales]),
                    WebUiPages::label('overrides.reset'),
                ),
                icon: 'arrow-counterclockwise',
                variant: 'danger',
                attributes: ['data-testid' => 'tyt-overrides-reset'],
                form: $resetForm,
            );
        }

        return new FormPage(
            header: new PageHeader(
                WebUiPages::label('overrides.edit_title'),
                WebUiPages::label('overrides.edit_intro'),
                back: new BackLink($indexUrl, WebUiPages::label('overrides.back'), 'tyt-overrides-back'),
            ),
            form: $form,
            submitLabel: WebUiPages::label('overrides.save'),
            cancelHref: $indexUrl,
            cancelLabel: WebUiPages::label('overrides.cancel'),
            deleteAction: $reset,
            bundle: WebUiPages::BUNDLE,
            layout: $this->pages->layout(),
            attributes: ['data-testid' => 'tyt-overrides-edit'],
            dangerZoneTitle: WebUiPages::label('overrides.reset_zone_title'),
            dangerZoneIntro: $reset instanceof Action ? WebUiPages::label('overrides.reset_zone_intro', ['%locales%' => $locales]) : null,
            formThemes: $this->pages->formThemes(),
            formAttributes: ['data-testid' => 'tyt-overrides-form', 'novalidate' => 'novalidate'],
        );
    }
}
