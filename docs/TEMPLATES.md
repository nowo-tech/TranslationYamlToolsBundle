# Templates

Pages of TranslationYamlToolsBundle on the UiKit page contract (REQ-UI-003, UiKit ^2.17). Both Web UIs are opt-in:
the **missing-translation log** (`missing_translation_log.web_ui`) and the **text-overrides desk**
(`overrides.web_ui`). `Nowo\TranslationYamlToolsBundle\Ui\MissingTranslationLogPageFactory` and
`Nowo\TranslationYamlToolsBundle\Ui\TranslationOverridePageFactory` build the UiKit view models
(`Nowo\UiKitBundle\View\*`) for the controllers; `Nowo\TranslationYamlToolsBundle\Ui\WebUiPages` (one service per Web
UI) resolves the layout (`web_ui.layout_template`, null = `nowo_ui_kit.layouts.admin`), the per-page templates
(`web_ui.templates.<page>`) and the form themes (`overrides.web_ui.form_themes`). Templates extend a UiKit scaffold and
fill data blocks only. Contract test: `tests/Integration/Contract/PageContractTest.php` (UiKit `PageContractTestCase`,
strict missing-translation rule); 1.x layout bridges: `tests/Integration/Contract/LegacyLayoutBridgeTest.php`. Twig
namespace: `@NowoTranslationYamlToolsBundle` (views in `src/Resources/views/`).

## Table of contents

- [Pages](#pages)
- [Shared page context](#shared-page-context)
- [Unique pages](#unique-pages)
- [Per-page overrides](#per-page-overrides)
- [Overriding templates (REQ-TWIG-001)](#overriding-templates-req-twig-001)
- [Bundle partials](#bundle-partials)
- [Layout bridges (1.x layouts)](#layout-bridges-1x-layouts)
- [Navigation](#navigation)
- [Allowed exceptions](#allowed-exceptions)

## Pages

Every view model passes `bundle: 'NowoTranslationYamlToolsBundle'` (theme exception
`nowo_ui_kit.theme.bundles.NowoTranslationYamlToolsBundle`) and `layout: WebUiPages::layout()`. Labels are
`NowoTranslationYamlToolsBundle` translation keys (`WebUiPages::label()`). POST actions are CSRF-only `FormView`s
(`MissingTranslationLogActionType`, `TranslationOverrideResetType`) rendered by the UiKit `action` partial; destructive
ones sit behind the UiKit confirm `<dialog>` (no inline `confirm()` / `on*=` handler).

| Page (`web_ui.templates` id) | Template | Scaffold | View model | Data blocks filled |
| --- | --- | --- | --- | --- |
| Missing-translation log (`missing_translation_log.web_ui.templates.index`) | `missing_translation_log/index.html.twig` | `page/list` | `ListPage` (header `missing_log.title` / `missing_log.intro`; `tabs` = `AreaNav` of the statuses Pending / Added / Validated (`?status=`), active = current status; actions Clear current status (`custom`, POST + CSRF-only `FormView` with the hidden `status`, `Confirm` `missing_log.clear_status.*`) and Clear all rows (`delete`, POST + CSRF-only `FormView`, `Confirm` `missing_log.clear_all.*`); columns `id` (priority 3), `locale`, `domain` (priority 2), `messageId` (required), `callSite`, `requestRoute`, `requestMethod` (priority 3), `requestPath` (priority 2), `hitCount` (end-aligned), `lastSeenAt` (priority 2); rows = `list<MissingTranslationLog>`; row action Mark added (`custom`, POST + CSRF-only `FormView`, pending rows only); `Pagination` (`page` / `per_page` 25 · 50 · 100 · 200, default 50); `EmptyState` `missing_log.empty` (`%status%`); caption = the status (visually hidden); `data-testid="tyt-missing-log"`, `data-status`) | `nowo_ui_list_row` (locale on `badge_neutral`, message id on `code`, empty request fields on `table_cell_muted`, last seen in a `<time>`) |
| Texts list (`overrides.web_ui.templates.index`) | `text_override/index.html.twig` | `page/list` | `ListPage` (header `overrides.title` / `overrides.intro`; `filters` = the GET `TranslationOverrideFilterType` (`q`, `group`), `filterFields` `q` (placeholder `overrides.search_placeholder`, width lg) and `group` (width md), submit `overrides.filter`, clear link when a query or group is set; columns `key` (required), `text` (`%locale%` = default locale), `overridden` (priority 2); rows = `{key, text, overridden}`; row action Edit (`edit`, GET); `Pagination` (`page` / `per_page` 25 · 50 · 100, default 50); `EmptyState` `overrides.empty`; actions column label visually hidden; `data-testid="tyt-overrides-index"`) | `nowo_ui_list_row` (key on `code`, overridden locales on `badge_info`), `nowo_ui_content` (the override count on `muted`, `data-testid="tyt-overrides-count"`, then `parent()`) |
| Edit one text (`overrides.web_ui.templates.edit`) | `text_override/edit.html.twig` | `page/form` | `FormPage` (header `overrides.edit_title` / `overrides.edit_intro`, back link `overrides.back` to the list of the same group; `form` = `TranslationOverrideTextsType` (named `texts`, one textarea per locale); submit `overrides.save`, cancel `overrides.cancel` = the list; `deleteAction` = Restore shipped text (`restore`, POST + CSRF-only `TranslationOverrideResetType` `FormView` with the hidden `confirm` marker, `Confirm` `overrides.reset_confirm_title` / `overrides.reset_confirm_message`) when a locale is overridden, delete zone `overrides.reset_zone_title` / `overrides.reset_zone_intro`; `formThemes` = `overrides.web_ui.form_themes`; `formAttributes` `data-testid="tyt-overrides-form"`, `novalidate`; `data-testid="tyt-overrides-edit"`) | `nowo_ui_form_body` (the key on `code`, then the UiKit `locale_tabs` partial — one panel per locale with the textarea row, an `edited` badge on `badge_info` and the shipped text in a `<details>`; a panel with an error is marked invalid and opened — then the REQ-TWIG-003 child loop for the remaining fields) |

## Shared page context

Variables passed next to `page` (1.x names, kept for templates set through `web_ui.templates.<page>` or overridden
under `templates/bundles/NowoTranslationYamlToolsBundle/`). The same `FormView`s back the page actions: render each
only once.

| Page | Variables |
| --- | --- |
| Missing-translation log | `rows`, `status`, `clear_status_form`, `clear_form`, `mark_added_forms` (row id => `FormView`, pending list only) |
| Texts list | `filter_form` (the `FormView` of `page.filters`), `rows`, `groups`, `group`, `query`, `default_locale`, `override_count` |
| Edit one text | `key`, `group`, `locales`, `default_locale`, `active_locale`, `texts`, `shipped`, `errors`, `max_length`, `overridden`, `form` (the `FormView` of `page.form`), `reset_form` (`null` when nothing is overridden) |
| Every page | `nowo_translation_yaml_tools_legacy_layout` (the wrapped 1.x layout, else `null`) |

The Twig globals `nowo_translation_yaml_tools_missing_log_layout_template` and `nowo_translation_yaml_tools_css_framework`
stay for host templates; the pages do not read them (deprecated, removed in 2.0).

## Unique pages

None: every page extends a UiKit scaffold.

## Per-page overrides

- `nowo_translation_yaml_tools.missing_translation_log.web_ui.templates.index` and
  `nowo_translation_yaml_tools.overrides.web_ui.templates.index|edit`: a host template that extends
  `@!NowoTranslationYamlToolsBundle/<template>` and overrides one data block (`nowo_ui_list_row`, `nowo_ui_list_after`,
  `nowo_ui_form_body`, `nowo_ui_form_aside`, `nowo_ui_content`, …), with `{{ parent() }}` where possible. Prefer
  theming the UiKit slots (`nowo_ui_kit.theme`) and scaffolds once for every bundle.
- Record such overrides in the application's override inventory.

```twig
{# templates/admin/missing_log.html.twig — missing_translation_log.web_ui.templates.index #}
{% extends '@!NowoTranslationYamlToolsBundle/missing_translation_log/index.html.twig' %}

{% block nowo_ui_list_after %}
    <p class="{{ nowo_ui_class('muted', page.bundle) }}">Add the keys to translations/*.yaml, then mark them as added.</p>
{% endblock %}
```

## Overriding templates (REQ-TWIG-001)

Any bundle template can be replaced by a file at `templates/bundles/NowoTranslationYamlToolsBundle/<subpath>` (same
relative path as under `src/Resources/views/`). `TwigPathsPass` registers the application directory first
(`prependPath`) and the bundle views after it (`addPath`), so **an application file at the override path always wins**
(the paths are registered whenever one of the Web UIs is enabled).

**Freeze rule.** An application file at the override path will not pick up vendor changes for that path until you
remove or merge it. Prefer, in this order: the UiKit host layout and theme (`nowo_ui_kit.layouts.admin`,
`nowo_ui_kit.theme`), translations, `web_ui.templates.<page>` (one data block), and only then a full-file copy — see
[USAGE](USAGE.md#overriding-templates-req-twig-001).

## Bundle partials

| Partial | Role |
| --- | --- |
| `missing_translation_log/_legacy_layout.html.twig` | Deprecated BC bridge around a 1.x `missing_translation_log.web_ui.layout_template` that renders `{% block nowo_translation_yaml_tools_missing_log_content %}` (and `missing_log_title` / `missing_log_head` / `missing_log_scripts`); removed in 2.0 |
| `text_override/_legacy_layout.html.twig` | Deprecated BC bridge around a 1.x `overrides.web_ui.layout_template` that renders `{% block body %}` (and `title` / `stylesheets` / `javascripts`); removed in 2.0 |
| `missing_translation_log/layout_integrate_dashboard_menu.html.twig`, `missing_translation_log/layout_integrate_breadcrumb_kit.html.twig` | Deprecated 1.x layouts into the DashboardMenu / BreadcrumbKit (< 2.7) dashboards (wrapped by the bridge above); removed in 2.0 |

The bundle ships no layout, no local pagination, flash, confirm, modal, page header, tabs, filters or empty-state
partial, no form theme, no CSS and no JavaScript: the scaffolds and the UiKit partials (`action`, `confirm`,
`list_filters`, `table`, `pagination`, `empty`, `flashes`, `locale_tabs`) render them.

## Layout bridges (1.x layouts)

`WebUiPages::layout()` loads the configured `web_ui.layout_template`; when it does not define `nowo_ui_content` (a
layout written for 1.x) the page extends the matching `_legacy_layout.html.twig` instead, which extends the configured
layout and maps the canonical blocks into its 1.x blocks (document title, `nowo-ui.css` + tokens, navigation, page
header, flashes, content, modals, UiKit scripts). A deprecation notice is triggered; see [UPGRADING](UPGRADING.md).
The former bundle default layouts (`missing_translation_log/layout.html.twig`, `text_override/layout.html.twig`) were
removed: configured explicitly, they mean "UiKit layout".

## Navigation

The status tabs of the missing-translation log are an in-page filter (`PageHeader::tabs`), not an area nav; the bundle
registers no area-nav provider. Hosts with DashboardMenu link `nowo_translation_yaml_tools_missing_log_index` and
`nowo_translation_yaml_tools_overrides_index` from their menu.

## Allowed exceptions

- The contract test declares no `allowedClasses()`.
- The missing-translation log prints recorded message ids as data: an id that looks like a translation key ending in
  `.label`, `.title`, … (e.g. a missing `app.page.title`) is expected output on that page; the contract fixtures use
  ids outside that pattern.
