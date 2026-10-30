# Configuration

## Table of contents

- [Reference (`nowo_translation_yaml_tools`)](#reference-nowo_translation_yaml_tools)
- [Web UI layout and theme (REQ-UI-003)](#web-ui-layout-and-theme-req-ui-003)
  - [Form profile (FormKit)](#form-profile-formkit)
- [Missing translation log (database)](#missing-translation-log-database)
- [Text overrides (database)](#text-overrides-database)
- [Machine translator by locale](#machine-translator-by-locale)
- [Machine translation locale map](#machine-translation-locale-map)
- [HTTP client](#http-client)
  - [Timeouts (REQ-RUNTIME-001)](#timeouts-req-runtime-001)
- [Symfony translation paths](#symfony-translation-paths)
- [Google Cloud Translation](#google-cloud-translation)
- [DeepL](#deepl)
- [LibreTranslate](#libretranslate)
- [Replacing the machine translator](#replacing-the-machine-translator)

## Reference (`nowo_translation_yaml_tools`)

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `default_locale` | `string\|null` | `null` | When set, used as the default **source** locale for `fill-missing`. When `null`, the bundle uses Symfony parameter **`translator.default_locale`** if defined, then **`kernel.default_locale`**, then **`en`**. On some Symfony versions (e.g. **8.x**), only **`kernel.default_locale`** may be available. |
| `yaml_tree_indent` | `int` | `4` | Spaces per indentation level when writing nested YAML (`tree`, `sort`, `fill-missing`). Allowed range: 2–12. |
| `yaml_tree_leaf_prefix_suffix` | `string` | `index` | Final segment appended to a conflicting leaf when using **`nowo:translation-yaml:tree --fix-leaf-prefix`** (e.g. key `a` becomes `a.index`). Single segment: `[a-zA-Z0-9_-]+`, no dots. |
| `machine_translator` | `string` | `google` | **Default** backend for `fill-missing` when `machine_translator_by_locale` does not match: `google`, `deepl`, or `libretranslate`. |
| `machine_translation_min_interval_ms` | `int` | `0` | Minimum delay between MT HTTP calls (`0` = no pacing). Use e.g. `100`–`250` for public LibreTranslate quotas. |
| `machine_translation_max_requests_per_run` | `int` | `0` | Max string translations per `fill-missing` run (`0` = unlimited). |
| `machine_translation_http_timeout` | `float` | `30` | Deadline (seconds, 1–300) for every Google / DeepL / LibreTranslate request: sent as both the HttpClient idle `timeout` and the total `max_duration`. See [Timeouts](#timeouts-req-runtime-001). |
| `machine_translator_by_locale` | `array<string, string>` | `{}` | Override the backend **per Symfony locale** (`google`, `deepl`, or `libretranslate`). The **target** locale is checked first, then the **source** locale, then `machine_translator`. Keys match like `machine_translation_locale_map` (`pt_BR` ≡ `pt-br`). |
| `deepl_endpoint` | `string` | `https://api.deepl.com/v2/translate` | DeepL translate URL. Use `https://api-free.deepl.com/v2/translate` if your key is on the **Free** plan. |
| `libretranslate_base_url` | `string` | `https://libretranslate.com` | Origin of the LibreTranslate server (no `/translate` path). Host must appear in **`libretranslate_allowed_hosts`**. Prefer **self-hosting** for reliability; the public demo enforces strict limits. |
| `libretranslate_api_key` | `string` | `''` | Optional API key when your LibreTranslate instance requires one; leave empty for open public endpoints. |
| `libretranslate_allowed_hosts` | `list<string>` | `[libretranslate.com]` | Hostname allowlist for **`libretranslate_base_url`** (SSRF mitigation). Subdomains of a listed host are allowed. |
| `libretranslate_allow_http` | `bool` | `false` | When **`true`**, allows `http://` LibreTranslate URLs (local/dev only). Default is HTTPS-only. |
| `machine_translation_locale_map` | `array<string, string>` | `{}` | Map **Symfony locale** identifiers to the **exact** `source` / `target` language code sent to the active backend (Google, DeepL, LibreTranslate). Keys are matched after normalizing case and `-` / `_` (e.g. `pt_BR`, `pt-br`, `PT_BR` share one entry). Values are sent **as-is**—use the format your provider expects (e.g. LibreTranslate `pt-br`, DeepL `PT-BR`, Google `pt-BR`). |
| `missing_translation_log` | `array` | see below | Runtime missing-key log (Doctrine). Use `missing_translation_log: false` to disable the subtree explicitly. |
| `missing_translation_log.enabled` | `bool` | `false` | When **`true`**, decorates **`translator`**, records missing keys per request / command / message, flushes on **`kernel.terminate`**, **`console.terminate`** and after each Messenger worker message (**`WorkerMessageHandledEvent`** / **`WorkerMessageFailedEvent`**), all at priority **`-1024`**. `missing_translation_log: ~` and `missing_translation_log: true` enable it (same as `enabled: true`). Persistence errors are logged and not rethrown (safe for FrankenPHP worker mode without `kernel.reset` — see [FRANKENPHP-WORKER-AUDIT](FRANKENPHP-WORKER-AUDIT.md)). In the usual **`index.php`** flow, **`terminate`** runs **after** **`$response->send()`**, so the client has already received the response body; the process may still run DB or Messenger work during terminate. Requires **`doctrine/orm`** and **`doctrine/doctrine-bundle`**; the bundle **prepends** ORM mapping for **`MissingTranslationLog`**; its table-prefix metadata listener is registered for every Doctrine connection (no longer only `default`). |
| `missing_translation_log.table_prefix` | `string` | `nowo_translation_` | Physical table name = **`{table_prefix}missing_log`** (only `[a-z0-9_]`, max 40 chars). Default → **`nowo_translation_missing_log`**. |
| `missing_translation_log.record_call_site` | `bool` | `true` | When **`true`**, each flush stores **`call_site`** (absolute **`file:line`** of the first plausible caller from `debug_backtrace`, excluding Symfony translation/Twig bridge internals). New hits can refresh **`call_site`**. Set **`false`** to avoid backtrace overhead on hot **`trans()`** paths. |
| `missing_translation_log.record_request_context` | `bool` | `true` | When **`true`** and an HTTP **`Request`** exists, the row also stores **`request_route`** (from **`_route`** when set), **`request_method`**, and **`request_path`** (**`getPathInfo()`** when non-empty). CLI / workers have no request, so those columns stay **`NULL`**. Set **`false`** if you do not want route or path persisted (privacy). |
| `missing_translation_log.async_persist` | `bool` | `false` | When **`true`**, flush uses **`async_persist_strategy`** instead of calling **`persistBuffer`** directly in the recorder. If the chosen strategy is unavailable (no bus, no **`event_dispatcher`**), the recorder **falls back** to synchronous **`persistBuffer`**. |
| `missing_translation_log.async_persist_strategy` | `string` | `messenger` | Only when **`async_persist`** is **`true`**: **`messenger`** dispatches **`MissingTranslationBufferMessage`** (needs **`symfony/messenger`** + **`messenger.default_bus`**; route the message to an async transport for workers). **`event_dispatcher`** dispatches **`MissingTranslationBufferEvent`** on the app **`event_dispatcher`**; the bundle registers **`MissingTranslationBufferDoctrinePersistListener`** at priority **-1024** to call **`persistBuffer`** unless a listener with higher priority called **`stopPropagation()`** (use that to enqueue the buffer and persist later without Messenger). |
| `missing_translation_log.web_ui.enabled` | `bool` | `false` | When **`true`** (and the log is enabled), registers **`MissingTranslationLogUiController`** (a UiKit list page, REQ-UI-003 — see [TEMPLATES](TEMPLATES.md); **`NowoUiKitBundle`** must be registered, checked at compile time) and runs **`TwigPathsPass`**: if **`templates/bundles/NowoTranslationYamlToolsBundle/`** exists, that path is prepended on the native Twig loader with namespace **`NowoTranslationYamlToolsBundle`**, then the bundle **`src/Resources/views`** path is appended with the same namespace so overrides win without **`twig.paths`** (see [USAGE](USAGE.md#overriding-templates-req-twig-001)). Requires **`symfony/twig-bundle`**. Also requires **`symfony/security-bundle`** unless **`security.allow_unauthenticated: true`**. |
| `missing_translation_log.web_ui.path_prefix` | `string` | `/_translation_yaml_tools/missing-log` | URL prefix for the routes you import (must start with **`/`**). Use a **trailing slash** on the list URL if your router enforces strict trailing slashes. |
| `missing_translation_log.web_ui.layout_template` | `string\|null` | `null` | Layout the page scaffold extends (REQ-UI-001 / REQ-UI-003). **`null`** = **`nowo_ui_kit.layouts.admin`** (set it once for every Nowo bundle). A host layout renders the UiKit canonical blocks; a **1.x layout** that renders `{% block nowo_translation_yaml_tools_missing_log_content %}` (e.g. the deprecated **`layout_integrate_dashboard_menu.html.twig`** / **`layout_integrate_breadcrumb_kit.html.twig`**) is wrapped in a deprecated bridge. The former default **`@NowoTranslationYamlToolsBundle/missing_translation_log/layout.html.twig`** (removed) means `null`. |
| `missing_translation_log.web_ui.templates.index` | `string` | `@NowoTranslationYamlToolsBundle/missing_translation_log/index.html.twig` | Per-page template override (REQ-UI-003): a host template that extends `@!NowoTranslationYamlToolsBundle/missing_translation_log/index.html.twig` and overrides one data block (see [TEMPLATES](TEMPLATES.md#per-page-overrides)). |
| `missing_translation_log.web_ui.css_framework` | `string` | `bootstrap5` | **Deprecated since 1.14** — set **`nowo_ui_kit.css_framework`** once for every bundle. Until 2.0 it only seeds `nowo_ui_kit.css_framework` (and `icon_set`) when the host leaves them unset; the pages read the UiKit theme only. Twig global **`nowo_translation_yaml_tools_css_framework`** (deprecated). |
| `missing_translation_log.web_ui.security.access_roles` | `list<string>` | `[ROLE_ADMIN]` | User must be granted **at least one** role for **`nowo_translation_yaml_tools_missing_log_*`** routes (REQ-UI-002). Empty list disables bundle-level role checks (firewall / custom checker only). |
| `missing_translation_log.web_ui.security.access_checker` | `string\|null` | `null` | Optional service id implementing **`MissingLogUiAccessCheckerInterface`**. **`null`** = built-in role checker (`ConfigurableMissingLogUiAccessChecker`). |
| `missing_translation_log.web_ui.security.allow_unauthenticated` | `bool` | `false` | **Dev/demo only.** When **`false`** (default), enabling the Web UI without **`security.authorization_checker`** fails container compilation. Set **`true`** only for local demos/tests that intentionally omit SecurityBundle — **never in production**: since 1.14 the container refuses it unless **`kernel.debug`** is true. Must be a **literal boolean**: an `%env(bool:…)%` placeholder is refused at compile time (since 1.14). |
| `missing_translation_log.web_ui.required_role` | `string\|null` | `ROLE_ADMIN` | **Deprecated BC alias** (deprecation notice when set since 1.14). Mapped to **`security.access_roles`** (`null` / empty → empty list). Prefer **`security.access_roles`**. |
| `missing_translation_log.web_ui.allow_unauthenticated` | `bool` | `false` | **Deprecated BC alias** for **`security.allow_unauthenticated`** (deprecation notice when set since 1.14). |
| `overrides` | `array` | see below | **Operator text overrides** (opt-in): per-locale DB replacements for editable catalogue messages, served by a `translator` decorator. See [Text overrides](#text-overrides-database). |
| `overrides.enabled` | `bool` | `false` | When **`true`**, decorates **`translator`** with **`OverridingTranslator`** (decoration priority **5**, outside the missing-log recorder) and prepends the ORM mapping for **`TextOverride\Entity\TranslationOverride`**. Requires **`doctrine/orm`** + **`doctrine/doctrine-bundle`** and the bundle registered in **every** environment where texts must apply (usually **all**, not only `dev`). |
| `overrides.table_prefix` | `string` | `nowo_translation_` | Physical table name = **`{table_prefix}override`** (`[a-z0-9_]`, max 40 chars). |
| `overrides.editable` | `array<string, list<string>>` | `{}` | Editable messages: **translation domain ⇒ key prefixes** (`''` = whole domain). Example: `{ messages: ['site.', 'legal.'], NowoUiKitBundle: ['loader.'] }`. Keys outside these prefixes are never listed nor editable. Domain names cannot contain `:`. |
| `overrides.locales` | `list<string>` | `[]` | Locales edited in the UI (one tab each). Empty ⇒ **`framework.enabled_locales`**, else the default locale only. The default locale (`default_locale` / translator default) is always first. |
| `overrides.cache_pool` | `string` | `cache.app` | Cache pool holding the override map (`Symfony\Contracts\Cache\CacheInterface`). |
| `overrides.cache_ttl` | `int` | `3600` | Seconds the map stays cached. Writes through the bundle invalidate it at once; the TTL bounds staleness after SQL / backup-restore writes. |
| `overrides.max_length` | `int` | `5000` | Max characters per text in the Web UI (HTTP 422 with a field error when exceeded). |
| `overrides.html_sanitizer` | `string\|null` | `null` | Service id implementing **`TranslationOverrideHtmlSanitizerInterface`**. `null` ⇒ **`SymfonyTranslationOverrideHtmlSanitizer`** (inline allowlist via **`symfony/html-sanitizer`**) when installed, else **`StripTagsTranslationOverrideHtmlSanitizer`** (drops every tag). Values containing `<` are sanitized on save **and** when the map is loaded, **before** message parameters are substituted (escape the parameters of messages you print with `\|raw`). Overrides must keep exactly the placeholders (`%name%`, ICU `{name}`) of the shipped text. |
| `overrides.web_ui.enabled` | `bool` | `false` | Registers **`TranslationOverrideController`** (list / edit / reset; UiKit list + form pages, REQ-UI-003 — see [TEMPLATES](TEMPLATES.md)). Requires **`symfony/twig-bundle`**, **`symfony/security-csrf`** + **`framework.csrf_protection`**, **`NowoUiKitBundle`** registered, and **`symfony/security-bundle`** unless `allow_unauthenticated` (compile-time check). |
| `overrides.web_ui.path_prefix` | `string` | `/_translation_yaml_tools/texts` | URL prefix for the imported routes (list URL ends with `/`). |
| `overrides.web_ui.layout_template` | `string\|null` | `null` | Layout the page scaffolds extend (REQ-UI-001 / REQ-UI-003). **`null`** = **`nowo_ui_kit.layouts.admin`**. A host layout renders the UiKit canonical blocks; a **1.x layout** that renders `{% block body %}` (and `title` / `stylesheets` / `javascripts`, the Symfony `base.html.twig` convention) is wrapped in a deprecated bridge. The former default **`@NowoTranslationYamlToolsBundle/text_override/layout.html.twig`** (removed) means `null`. |
| `overrides.web_ui.templates.index` / `.edit` | `string` | the bundle pages | Per-page template overrides (REQ-UI-003), see [TEMPLATES](TEMPLATES.md#per-page-overrides). |
| `overrides.web_ui.form_themes` | `list<string>` | `['@NowoUiKit/form/nowo_ui_layout.html.twig']` | Form themes of the edit page (UiKit `FormPage::$formThemes`). `[]` = only the host `twig.form_themes`. |
| `overrides.web_ui.security.access_roles` | `list<string>` | `[ROLE_ADMIN]` | User needs **at least one** (checked with `isGranted()`, so voter attributes work too). Empty ⇒ deny everyone. |
| `overrides.web_ui.security.allow_unauthenticated` | `bool` | `false` | **Dev/demo only**: skip the role check (no SecurityBundle needed). Never in production: since 1.14 the container refuses it unless **`kernel.debug`** is true. Must be a **literal boolean**: an `%env(bool:…)%` placeholder is refused at compile time (since 1.14). |

Example:

```yaml
nowo_translation_yaml_tools:
    default_locale: null
    yaml_tree_indent: 4
    yaml_tree_leaf_prefix_suffix: index
    machine_translator: google
    deepl_endpoint: 'https://api.deepl.com/v2/translate'
    libretranslate_base_url: 'https://libretranslate.com'
    libretranslate_api_key: ''
    # machine_translation_locale_map:
    #     pt_BR: 'pt-br'   # Symfony locale => API code (see table above)
    # machine_translator_by_locale:
    #     pt_BR: libretranslate   # use LibreTranslate when translating to/from this locale (see below)
    # missing_translation_log:
    #     enabled: true
    #     table_prefix: nowo_translation_
    #     web_ui:
    #         enabled: true
    #         path_prefix: '/_translation_yaml_tools/missing-log'
    #         # layout_template: null   # default: nowo_ui_kit.layouts.admin (REQ-UI-003)
```

## Web UI layout and theme (REQ-UI-003)

Both opt-in Web UIs (missing-translation log, text-overrides desk) are UiKit page scaffolds (list / form) fed by view
models; the bundle ships no HTML shell, no CSS and no CDN link. Register **`Nowo\UiKitBundle\NowoUiKitBundle`**
(`nowo-tech/ui-kit-bundle` ^2.17, a dependency of this bundle) and configure the host **once** for every Nowo bundle:

```yaml
# config/packages/nowo_ui_kit.yaml
nowo_ui_kit:
    css_framework: bootstrap5            # the stack your layout loads (bootstrap5 | tailwind | none | …)
    layouts:
        admin: 'layouts/admin.html.twig'  # your admin layout or a one-file bridge (below)
    # theme: { classes: {…}, tokens: {…} } # class map / design tokens for every bundle page
```

One-file bridge into an existing `base.html.twig` (the reference integration):

```twig
{# templates/layouts/admin.html.twig #}
{% extends '@NowoUiKit/layout/admin.html.twig' %}

{% block nowo_ui_styles %}
    <link rel="stylesheet" href="{{ asset('build/app.css') }}">
    {{ parent() }}
{% endblock %}

{% block nowo_ui_navigation %}{{ include('_partials/admin_menu.html.twig') }}{% endblock %}
```

Per-bundle exceptions: `web_ui.layout_template` (one UI only), `web_ui.templates.<page>` (one page),
`nowo_ui_kit.theme.bundles.NowoTranslationYamlToolsBundle` (slots / tokens). Pages, view models and data blocks:
[TEMPLATES](TEMPLATES.md); upgrade notes: [UPGRADING](UPGRADING.md#unreleased).

### Form profile (FormKit)

Every Web UI form (`MissingTranslationLogActionType`, `TranslationOverrideFilterType`, `TranslationOverrideTextsType`,
`TranslationOverrideResetType`) is a FormKit Type (`#[FormKitConfig('translation_yaml_tools')]` + `FormOptionsTrait`,
REQ-TWIG-005 rule 11). **`nowo-tech/form-kit-bundle`** is a required dependency: keep
**`Nowo\FormKitBundle\NowoFormKitBundle`** registered (Flex does it). The bundle prepends the profile
**`nowo_form_kit.profiles.translation_yaml_tools`**:

```yaml
nowo_form_kit:
    profiles:
        translation_yaml_tools:
            alias: translation_yaml_tools
            translation_domain: NowoTranslationYamlToolsBundle
            auto_placeholder: false   # the types pass explicit label / help keys
            auto_help: false
            defaults: { attr: {}, row_attr: {} }   # framework-neutral: the form theme adds the CSS classes
            field_types: {}
```

To customise the fields (CSS classes, row wrappers, per-type defaults), define
**`nowo_form_kit.profiles.translation_yaml_tools`** in your `config/packages/nowo_form_kit.yaml`: when the host
defines that profile, the bundle does not prepend its own, so copy the keys above and change what you need. The
form **theme** stays the host `twig.form_themes` plus `overrides.web_ui.form_themes` (edit page) — no bundle page
hard-codes `form_theme`.

## Missing translation log (database)

1. Install **`doctrine/orm`** and **`doctrine/doctrine-bundle`** in your application (the bundle lists them under `suggest`).
2. Enable the feature and optional **table prefix** / **web UI**:

```yaml
nowo_translation_yaml_tools:
    missing_translation_log:
        enabled: true
        table_prefix: nowo_translation_   # table: nowo_translation_missing_log
        web_ui:
            enabled: true
            path_prefix: '/_translation_yaml_tools/missing-log'
            # layout_template: null   # default: nowo_ui_kit.layouts.admin; CSS stack: nowo_ui_kit.css_framework
            security:
                access_roles: [ROLE_ADMIN]
                # access_checker: App\Security\MissingLogUiAccessChecker
                # allow_unauthenticated: false   # default; never true in production
```

3. Create the table (migrations / `doctrine:schema:update` in dev only). Entity: **`Nowo\TranslationYamlToolsBundle\Entity\MissingTranslationLog`**; table name follows **`{table_prefix}missing_log`**. The bundle metadata adds a **unique** constraint on **`message_id`**, **`domain`**, and **`locale`**. From **0.3.2** onward, **`persistBuffer`** is duplicate-safe for concurrent flushes. From **0.3.3** onward, statements resolve **physical column names** from Doctrine class metadata. From **1.2.1** onward, MySQL/MariaDB and SQLite/PostgreSQL use a **native UPSERT** (no **`SQLSTATE[23000]`** / **1062** exception on conflict); other platforms use **UPDATE-then-INSERT** with a race catch. On conflict the row increments **`hit_count`**, refreshes **`last_seen_at`**, and updates optional context columns (**`call_site`**, **`request_route`**, **`request_method`**, **`request_path`**) when the new value is non-null.

4. **Import HTTP routes** (not automatic) with the same prefix as **`web_ui.path_prefix`**:

```yaml
# config/routes/nowo_translation_yaml_tools_ui.yaml
nowo_translation_yaml_tools_missing_log_ui:
    resource: '@NowoTranslationYamlToolsBundle/Resources/config/routes/missing_translation_log_ui.yaml'
    type: yaml
    prefix: '%nowo_translation_yaml_tools.missing_translation_log.web_ui.path_prefix%'
```

The Flex recipe adds this under **`config/routes/dev/`** when you install the bundle (dev only).

5. **Framework** (for the Twig UI and CSRF on “Mark added”):

```yaml
framework:
    csrf_protection: true
```

Also install **`symfony/security-csrf`** (and enable **`session`** if you do not already) so the **`csrf_token()`** Twig function is available.

6. **SecurityBundle (required for the Web UI in real apps):** install **`symfony/security-bundle`**. Without it, container compilation fails unless you explicitly set **`security.allow_unauthenticated: true`** (demos/tests only). Recommended **`access_control`**:

```yaml
# config/packages/security.yaml (fragment)
security:
    access_control:
        - { path: ^/_translation_yaml_tools, roles: ROLE_ADMIN }
```

Adjust the path to match **`web_ui.path_prefix`**. Bundle-level **`security.access_roles`** (default **`[ROLE_ADMIN]`**) is enforced by **`MissingLogUiAccessSubscriber`** / **`MissingLogUiAccessCheckerInterface`** in addition to your firewall. Legacy **`required_role`** / top-level **`allow_unauthenticated`** still work as BC aliases.

7. **Optional deferred flush** (the response is usually already sent before **`kernel.terminate`**; this further decouples *how* you persist):
   - **`async_persist_strategy: messenger`** (default when **`async_persist: true`**): install **`symfony/messenger`**, route **`MissingTranslationBufferMessage`** to an async transport; the bundle registers **`PersistMissingTranslationBufferMessageHandler`** when Messenger is available.
   - **`async_persist_strategy: event_dispatcher`**: no Messenger required. Set **`async_persist: true`** and **`async_persist_strategy: event_dispatcher`**. Subscribe to **`MissingTranslationBufferEvent`**; to **only** enqueue and persist in your own worker, call **`$event->stopPropagation()`** after enqueueing so the builtin Doctrine listener (priority **-1024**) does not run.

8. **Routes** (fixed names): **`nowo_translation_yaml_tools_missing_log_index`** (GET list + `?status=pending|added|validated`), **`nowo_translation_yaml_tools_missing_log_mark_added`** (POST, CSRF id **`missing_log_mark_added`**).

9. **Console** (registered only when the log feature is enabled):

- **`nowo:translation-yaml:missing-log-list`** — `--status=pending|added|validated` (default `pending`), `--limit=…`
- **`nowo:translation-yaml:missing-log-mark-added <id>`** — mark a row as **added** (string present in YAML)
- **`nowo:translation-yaml:missing-log-validate <id>`** — mark as **validated**; optional **`--note=`**

Row fields include **`message_id`** (max 500 chars), **`domain`** (max 180), **`locale`** (max 32), **`status`**, **`hit_count`**, **`call_site`** (nullable backtrace **`file:line`**, max 1024 chars), **`request_route`** (nullable, max 180), **`request_method`** (nullable, max 8), **`request_path`** (nullable **`pathInfo`**, max 2048), **`first_seen_at`**, **`last_seen_at`**, **`status_changed_at`**, **`notes`**. Oversized string values are truncated on write (ellipsis suffix where the column is long enough) so inserts never fail with **SQLSTATE[22001]** / MySQL **1406**.

The log records **runtime `TranslatorInterface::trans()`** lookups that miss the catalogue for the requested locale; it is **not** a full inventory of every untranslated string in the app, and **`call_site`** from Twig often points at **compiled** Twig under **`var/cache`**, not the **`.twig`** source line. With **`record_request_context`** (default **true**), HTTP rows also get **`request_route`**, **`request_method`**, and **`request_path`**. See [USAGE — Missing translation log: coverage and call_site](USAGE.md#missing-translation-log-coverage-and-call_site).

## Text overrides (database)

Operators replace shipped catalogue texts per locale without a deploy. Overrides are stored in **`{table_prefix}override`** and served by **`OverridingTranslator`** (decorates `translator`): Twig `|trans`, forms, mails and PHP `trans()` see the edited text; `getCatalogue()` keeps returning the shipped text. Only an exact *(locale, domain, key)* match is replaced; `%param%`, plural and ICU formatting work as for catalogue messages.

1. Install the bundle for **all** environments (`composer require nowo-tech/translation-yaml-tools-bundle`, `['all' => true]` in `config/bundles.php`) plus **`doctrine/orm`** and **`doctrine/doctrine-bundle`**. Optional: **`symfony/html-sanitizer`** (recommended — keeps links/emphasis instead of stripping all markup).
2. Configure:

```yaml
nowo_translation_yaml_tools:
    overrides:
        enabled: true
        editable:
            messages: ['site.', 'legal.', 'error.']
            NowoUiKitBundle: ['loader.']
        # locales: [es, en]        # default: framework.enabled_locales
        # html_sanitizer: app.text_override_sanitizer
        web_ui:
            enabled: true
            path_prefix: '/admin/texts'
            # layout_template: null   # default: nowo_ui_kit.layouts.admin (UiKit canonical blocks)
            security:
                access_roles: [ROLE_ADMIN]
```

3. **Generate the Doctrine migration** in the host (the bundle ships none):

```bash
php bin/console doctrine:migrations:diff   # review, then
php bin/console doctrine:migrations:migrate
```

The diff creates (names depend on `table_prefix`):

```sql
CREATE TABLE nowo_translation_override (
    id INT AUTO_INCREMENT NOT NULL,          -- SERIAL / INTEGER PRIMARY KEY per platform
    locale VARCHAR(16) NOT NULL,
    domain VARCHAR(128) NOT NULL,
    message_key VARCHAR(255) NOT NULL,
    value LONGTEXT NOT NULL,                 -- TEXT
    created_at DATETIME NOT NULL,            -- (DC2Type:datetime_immutable)
    updated_at DATETIME NOT NULL,
    updated_by VARCHAR(180) DEFAULT NULL,    -- user identifier of the last editor
    UNIQUE INDEX tyt_ovr_uq_<hash> (locale, domain, message_key),
    PRIMARY KEY(id)
);
```

Until the table exists, the override map is empty (a warning is logged) and the shipped texts are shown.

4. Import the routes (same prefix as `web_ui.path_prefix`):

```yaml
# config/routes/nowo_translation_yaml_tools_overrides.yaml
nowo_translation_yaml_tools_overrides_ui:
    resource: '@NowoTranslationYamlToolsBundle/Resources/config/routes/translation_override_ui.yaml'
    type: yaml
    prefix: '%nowo_translation_yaml_tools.overrides.web_ui.path_prefix%'
```

Routes: **`nowo_translation_yaml_tools_overrides_index`** (GET `?q=&group=`), **`…_edit`** (GET/POST `?key=`), **`…_reset`** (POST `?key=`). Keys of the `messages` domain are addressed as-is; other domains as **`Domain:key`** (e.g. `NowoUiKitBundle:loader.loading`). Unknown / non-editable keys answer **404**.

5. Security: every POST carries a CSRF token (`nowo_tyt_override_edit_<ref>` / `nowo_tyt_override_reset_<ref>`); reset also needs the confirmation checkbox (no inline JavaScript). Also protect the prefix in `access_control`. The edit page has one inline `<style>` (CSS-only locale tabs) that carries **`nonce="{{ app.request.attributes.get('csp_nonce') }}"`** when your CSP listener sets the **`csp_nonce`** request attribute.

Cache: the map is cached in `cache_pool` for `cache_ttl` seconds and memoised per request (`ResetInterface`, worker-safe). Writes outside the bundle (SQL, backup restore) are picked up after the TTL or after `TranslationOverrides::invalidate()`.

## Machine translator by locale

`machine_translator_by_locale` chooses **which service** (Google vs DeepL vs LibreTranslate) runs for a given Symfony locale. Resolution order for each `translate` call: **target** locale → **source** locale → `machine_translator`.

All three backends are always registered; ensure the env vars / keys for backends you might route to are set (e.g. `DEEPL_AUTH_KEY` if any locale uses `deepl`).

## Machine translation locale map

Use `machine_translation_locale_map` when Symfony’s locale naming does not match what your provider expects. The built-in translators apply their default normalization only for locales **not** listed in the map.

If two YAML keys normalize to the same canonical key (e.g. `pt_BR` and `pt-br`), the **last** processed entry wins.

## HTTP client

`fill-missing` uses Symfony’s `HttpClientInterface`. Enable the Framework HTTP client in your app (as in the demos):

```yaml
framework:
    http_client: true
```

### Timeouts (REQ-RUNTIME-001)

Every machine-translation request sets **`timeout`** (idle: no bytes received for that long) **and** **`max_duration`** (total wall time, including connect) to `machine_translation_http_timeout` (default **30 s**). A request that exceeds either deadline fails with a Symfony HttpClient `TransportException`; `fill-missing` stops with `Translation failed for key "…"` and exits non-zero without writing the target file, so a hung backend never blocks a PHP thread indefinitely.

`fill-missing` is a console command (no `max_execution_time` in the CLI SAPI), so the HTTP deadline is the only bound per call. If you run it from a web request or a FrankenPHP worker (for example through a custom controller), keep this hierarchy, innermost first:

| Layer | Default / demo value | Rule |
|-------|----------------------|------|
| `machine_translation_http_timeout` | 30 s | Fires first; the request is aborted |
| PHP `max_execution_time` / `max_input_time` | 60 s (demo) | Strictly greater than the HTTP timeout |
| Caddy `servers.timeouts.write` (FrankenPHP) | 90 s (demo) | Strictly greater than PHP |
| FrankenPHP `max_wait_time` | 30 s (demo) | Caps the wait for a free PHP thread (then 504) |

When you raise `machine_translation_http_timeout`, raise the PHP and Caddy deadlines in the same change. The demo values live in `demo/symfony8/docker/frankenphp/Caddyfile*` (see [DEMO-FRANKENPHP.md](DEMO-FRANKENPHP.md#timeout-hierarchy-req-runtime-001)).

## Symfony translation paths

The bundle discovers YAML files under:

1. **`translator.default_path`** (FrameworkBundle parameter) when defined.
2. Otherwise **`%kernel.project_dir%/translations`**.
3. Plus **`framework.translator.default_path`** and **`framework.translator.paths`** entries parsed from `config/packages/**/translation.yaml` (or `.yml`).

Absolute paths in YAML are resolved with the container parameter bag (e.g. `%kernel.project_dir%`).

## Google Cloud Translation

- Env var: **`GOOGLE_TRANSLATE_API_KEY`** (required when `machine_translator` or `machine_translator_by_locale` routes to `google`).
- API: [Translation API v2](https://cloud.google.com/translate/docs/reference/rest/v2/translate).
- Do **not** commit real keys; use `.env.local` or your secret manager.

## DeepL

- Env var: **`DEEPL_AUTH_KEY`** (required when `machine_translator` or `machine_translator_by_locale` routes to `deepl`).
- Set `deepl_endpoint` to the **Free** API host if your key is not valid on the Pro endpoint (see table above).
- API: [Translate text](https://developers.deepl.com/docs/api-reference/translate).

## LibreTranslate

- No Google/DeepL key is required when the active backend is LibreTranslate only (`machine_translator: libretranslate` or `machine_translator_by_locale` mapping to `libretranslate`) with the default public URL and an empty `libretranslate_api_key`.
- Public servers apply **strict rate limits** and may return errors when busy; for repeated or bulk `fill-missing` runs, **host your own** [LibreTranslate](https://github.com/LibreTranslate/LibreTranslate) and point `libretranslate_base_url` at it.
- Set `libretranslate_api_key` if your instance is configured to require a key.
- API: [LibreTranslate API](https://github.com/LibreTranslate/LibreTranslate/blob/main/API.md).

## Replacing the machine translator

By default, `MachineTranslatorInterface` points to **`RoutingMachineTranslator`**, which delegates to the three built-in services. Register your own implementation in application DI and **alias** `MachineTranslatorInterface` to it to bypass routing and bundle backends entirely (that alias overrides the bundle default). `machine_translator`, `machine_translator_by_locale`, and `machine_translation_locale_map` then no longer apply unless your code uses them explicitly.
