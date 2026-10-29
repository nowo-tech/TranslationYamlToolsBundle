# Usage

## Table of contents

- [Commands overview](#commands-overview)
- [Interactive selection](#interactive-selection)
- [Examples](#examples)
- [Strict parity in CI and PHPUnit](#strict-parity-in-ci-and-phpunit)
  - [`audit --strict`](#audit---strict)
  - [PHPUnit trait](#phpunit-trait)
- [When tree conversion is impossible](#when-tree-conversion-is-impossible)
- [File naming](#file-naming)
- [Twig — missing translation log Web UI](#twig--missing-translation-log-web-ui)
- [Missing translation log: coverage and call_site](#missing-translation-log-coverage-and-call_site)
  - [What is recorded (and what is not)](#what-is-recorded-and-what-is-not)
  - [Call site (`call_site`) and Twig](#call-site-call_site-and-twig)
- [Text overrides (operator-edited texts)](#text-overrides-operator-edited-texts)
  - [Web UI desk](#web-ui-desk)
  - [Form bridge (host forms)](#form-bridge-host-forms)
  - [HTML sanitizing](#html-sanitizing)
- [Bundle translations: locales and overrides](#bundle-translations-locales-and-overrides)
- [Overriding templates (REQ-TWIG-001)](#overriding-templates-req-twig-001)
- [Symfony 8 demo](#symfony-8-demo)

## Commands overview

| Command | Purpose |
|---------|---------|
| `nowo:translation-yaml:tree` | Pick **domain** (and optionally **locale**); validates that dot-keys can be turned into a nested tree; writes nested YAML using `yaml_tree_indent`. Omit **`--locale`** to convert **every** locale file for the domain. Optional **`--fix-leaf-prefix`** resolves leaf/prefix conflicts (see [When tree conversion is impossible](#when-tree-conversion-is-impossible)). |
| `nowo:translation-yaml:flatten` | Same pattern; flattens nested maps to a **single-level** map whose keys are **dot paths** (e.g. `demo.title`). Omit **`--locale`** to flatten **all** locale files for the domain. |
| `nowo:translation-yaml:sort` | Same pattern; sorts associative keys recursively. Omit **`--locale`** to sort **all** locale files for the domain. |
| `nowo:translation-yaml:fill-missing` | Uses the **default source locale** (see [Configuration](CONFIGURATION.md)) and a **target locale** file; translates missing keys via the configured backend (**Google**, **DeepL**, or **LibreTranslate**). |
| `nowo:translation-yaml:audit` | Read-only **audit** of all domains (or `--domain`): tree convertibility (with `leaf_and_prefix` conflict counts and samples), recursive **alphabetical** key order, and **missing keys** vs `--source-locale` (default: Symfony default). Domains that pass every check show **one** summary line (no per-locale breakdown). Exits **non-zero** if any domain has issues. **`--strict`** also fails on **extra** keys and **empty** values and lists the keys (see [Strict parity](#strict-parity-in-ci-and-phpunit)). |

The write commands (`tree`, `flatten`, `sort`, `fill-missing`) accept **`--inline`**: dump translations as compact **YAML flow style** (e.g. `{ demo: { title: … } }` or `{ demo.title: … }`) instead of expanded blocks and multi-line literals.

## Interactive selection

Run without `--domain` in a TTY: the commands print discovered directories, list **domains** from files named `domain.locale.yaml`, then prompt for **domain** with a **choice list** (arrow keys in most terminals).

For **`tree`**, **`flatten`**, and **`sort`**, **`--locale` is optional**: if you omit it (interactive or not), the command runs for **every locale** discovered for that domain. Commands that target a **single** target file (e.g. **`fill-missing`**) still ask for **locale** / use **`--target-locale`** as before.

Use `--no-interaction` together with explicit options for CI or scripts (for **`tree`**, **`flatten`**, **`sort`**, **`--domain` alone** is enough to process all locales).

## Examples

Dry-run tree conversion:

```bash
php bin/console nowo:translation-yaml:tree --domain=messages --locale=en --dry-run
```

Apply nested tree (one locale):

```bash
php bin/console nowo:translation-yaml:tree --domain=messages --locale=en
```

Apply to **all** locales for the domain:

```bash
php bin/console nowo:translation-yaml:tree --domain=messages
```

If a leaf/prefix conflict blocks conversion, rename blocking leaves with the configured suffix (default **`index`**) — see [Configuration](CONFIGURATION.md) **`yaml_tree_leaf_prefix_suffix`**:

```bash
php bin/console nowo:translation-yaml:tree --domain=messages --locale=en --fix-leaf-prefix
php bin/console nowo:translation-yaml:tree --domain=messages --fix-leaf-prefix --leaf-prefix-suffix=caption
```

Sort keys (one locale):

```bash
php bin/console nowo:translation-yaml:sort --domain=messages --locale=en
```

Sort **all** locale files for the domain:

```bash
php bin/console nowo:translation-yaml:sort --domain=messages
```

Sort and write **inline (flow) YAML**:

```bash
php bin/console nowo:translation-yaml:sort --domain=messages --locale=en --inline
```

Flatten nested keys to dot paths at the file root:

```bash
php bin/console nowo:translation-yaml:flatten --domain=messages --locale=en --dry-run
php bin/console nowo:translation-yaml:flatten --domain=messages --locale=en
```

Flatten **all** locale files for the domain:

```bash
php bin/console nowo:translation-yaml:flatten --domain=messages
```

Fill missing Spanish keys from English (default locale):

```bash
php bin/console nowo:translation-yaml:fill-missing --domain=messages --target-locale=es
```

Audit every domain (CI-friendly; fails if anything is wrong):

```bash
php bin/console nowo:translation-yaml:audit
php bin/console nowo:translation-yaml:audit --source-locale=en --domain=messages
php bin/console nowo:translation-yaml:audit --strict   # also extra keys and empty values
```

Fill and write nested YAML:

```bash
php bin/console nowo:translation-yaml:fill-missing --domain=messages --target-locale=es --tree
```

## Strict parity in CI and PHPUnit

### `audit --strict`

```bash
php bin/console nowo:translation-yaml:audit --strict --source-locale=en
```

On top of the normal audit, `--strict` fails (exit 1) when a locale has keys that are **missing** from it or **extra** (absent from the source locale), or **empty** values (blank string, `null`, empty map — source locale included). The offending keys are listed (`missing:`, `extra:`, `empty:`; first 20 per kind, then `… and N more`). Without `--strict` the output and exit code are unchanged.

### PHPUnit trait

`Nowo\TranslationYamlToolsBundle\Testing\TranslationParityAssertions` runs the same checks on a translation directory, without booting the kernel. It needs `phpunit/phpunit` (suggested, not required by the bundle).

```php
use Nowo\TranslationYamlToolsBundle\Testing\TranslationParityAssertions;
use PHPUnit\Framework\TestCase;

final class TranslationParityTest extends TestCase
{
    use TranslationParityAssertions;

    private const DIR = __DIR__ . '/../translations';

    public function testEveryLocaleHasTheSourceKeys(): void
    {
        self::assertTranslationKeyParity(
            self::DIR,
            sourceLocale: 'en',
            locales: ['en', 'es', 'fr'],            // expected locales: a missing file reports every key
            allowedDifferences: ['fr' => ['messages:beta.*']],
        );
    }

    public function testNoEnglishSentenceLeftUntranslated(): void
    {
        self::assertNoUntranslatedValues(
            self::DIR,
            domains: ['messages', 'form'],
            allowedSameAsSource: ['*' => ['messages:browser.chrome.name']],
        );
    }
}
```

| Method | Parameters (defaults) | Fails on |
|--------|----------------------|----------|
| `assertTranslationKeyParity()` | `string $translationDir`, `string $sourceLocale = 'en'`, `?array $domains = null` (all found), `?array $locales = null` (all found; absent files skipped), `array $allowedDifferences = []`, `bool $checkEmpty = true`, `string $message = ''` | `missing`, `extra`, `empty` keys; `source_missing` (no or empty source file for a domain); `yaml_error` |
| `assertNoUntranslatedValues()` | `string $translationDir`, `string $sourceLocale = 'en'`, `?array $domains = null`, `?array $locales = null`, `array $allowedSameAsSource = []`, `bool $multiWordOnly = true`, `string $message = ''` | `untranslated`: target value identical to the source value. With `$multiWordOnly`, only source values containing a space and not starting with `%` or `{` are checked |
| `assertLiteralKeysExist()` | `string $sourceDir`, `string $translationDir`, `string $locale = 'en'`, `string $domain = 'messages'`, `int $minimumChecked = 1`, `array $allowed = []`, `string $message = ''` | `unknown literal`: a literal key passed to `->trans('a.b')` (default domain, at most a parameter argument) or `addFlash('type', 'a.b')` in `$sourceDir` that the `$domain.$locale` catalogue lacks — the key is missing in **every** locale, which parity checks cannot see. Calls with an explicit domain or a computed key are skipped; `$minimumChecked` fails a scan that finds nothing |

Allowlists map a locale (or `*` for every locale) to `domain:key` entries; `fnmatch()` patterns are accepted (`messages:legal.*`). The failure message lists one line per violation, e.g. `es messages: missing key "app.title"`.

The underlying service `Nowo\TranslationYamlToolsBundle\Service\TranslationParityChecker` (`checkParity()`, `checkUntranslated()`, `missingKeys()`, `extraKeys()`, `emptyKeys()`, `untranslatedKeys()`, `format()`) can be used directly from scripts.

## When tree conversion is impossible

If a **leaf key** is also a **prefix** of another key (for example both `a` and `a.b` as distinct leaves after flattening), nested YAML cannot represent the same map unambiguously. The `tree` command (and `fill-missing --tree`) stops and prints which prefix causes the conflict — unless you pass **`--fix-leaf-prefix`**, which renames those leaves by appending **`.{suffix}`** (default suffix from **`yaml_tree_leaf_prefix_suffix`**, default **`index`**; override with **`--leaf-prefix-suffix=`**). If the target key already exists, the command reports an error.

## File naming

Files must follow Symfony’s usual pattern:

`translations/<domain>.<locale>.yaml` (or `.yml`).

## Twig — missing translation log Web UI

When **`missing_translation_log.web_ui.enabled`** is **`true`**, the bundle exposes HTML under the imported route prefix (see [Configuration](CONFIGURATION.md)). The controller renders **`@NowoTranslationYamlToolsBundle/missing_translation_log/index.html.twig`**. Optional layout integration with **NowoDashboardMenuBundle** / **NowoBreadcrumbKitBundle** uses the bridge templates and **`web_ui.layout_template`** (see [Configuration](CONFIGURATION.md)).

**Forms (REQ-TWIG-003 / REQ-TWIG-005):** every Web UI form (missing log and text overrides) is a Symfony Form type under `Nowo\TranslationYamlToolsBundle\Form\` with CSRF on, created in the controller and rendered with `form_start(form)`, the child loop `{% for child in form %}{% if not child.rendered %}{{ form_row(child) }}{% endif %}{% endfor %}` and `form_end(form)`; submit buttons stay outside the loop. When you override a template, keep that pattern — do not reintroduce raw `<form>` / `<input>` markup (see [UPGRADING](UPGRADING.md#unreleased) for the template variables and field names). The demo follows the same rule (its login page uses a `LoginFormType` with the `form_login` field names `_username` / `_password` / `_csrf_token`), and `make check-no-raw-html-form` (part of `release-check`) fails on raw form controls in bundle views and demo templates; the only allowlisted file is the `text_override/form_theme.html.twig` form theme (it implements the locale-tabs widget block).

The log table enforces one row per **`(message_id, domain, locale)`**; from **0.3.2** onward, flushes use duplicate-safe persistence. From **1.2.1** onward, the common engines use a native UPSERT so conflict does not throw a SQL duplicate-key exception (details in [Configuration](CONFIGURATION.md#missing-translation-log-database)).

## Missing translation log: coverage and call_site

### What is recorded (and what is not)

The feature is implemented by decorating Symfony’s **`TranslatorInterface`**: **`RecordingTranslatorDecorator`** runs **before** each **`trans()`** call and, when the active **`MessageCatalogue`** for the **requested locale** does **not** **`define`** the id in that domain, buffers a row for flush on **`kernel.terminate`** (or your async strategy).

**You do get logs for** typical Symfony usage that resolves to **`Translator::trans()`** / **`TranslatorInterface::trans()`**: controllers using **`$this->trans()`**, Twig **`|trans`**, **`{% trans %}`** (they compile down to the same translator), many forms and validators that use the translator service, etc.

**You do not get a complete “every human-visible untranslated string” audit:**

- Code that reads **`MessageCatalogue`** directly, custom wrappers that bypass the decorated translator, or another **`TranslatorInterface`** instance not wired as the app translator, will not hit the decorator.
- Lookups that never call **`trans()`** (hard-coded strings, client-side only JS, etc.) are invisible to this layer.
- The row reflects **“missing in catalogue X for locale L”** — Symfony may still **display** a fallback (another locale or the raw id) after the check; the log is still useful as “not defined for L”.

So the log is a **strong signal for runtime `trans()` gaps**, not a static proof that every string in the UI is translated.

### Call site (`call_site`) and Twig

When **`record_call_site`** is **`true`**, **`TranslationCallSiteResolver`** walks **`debug_backtrace()`**, skips this bundle’s decorator, recorder, **`MissingTranslationLogCallSiteBuilder`**, Symfony’s translation internals, and **`symfony/twig-bridge/Extension/TranslationExtension.php`**, then takes the **first remaining frame**.

That design avoids pointing every hit at the Twig bridge file, but for Twig-rendered pages the “first plausible” frame is often **not** your **`templates/.../*.twig`** path: it is frequently **`var/cache/.../twig/*.php`** (compiled template), **`Twig\Template`**, or another engine frame. **PHP call sites** (e.g. a controller calling **`$this->trans('missing.id')`**) tend to map to the real **`.php`** file and line.

**Yes — it is expected** that **`call_site`** sometimes does not match the Twig source line; improving that would require Twig/source-map style metadata beyond what **`debug_backtrace`** exposes here.

When **`missing_translation_log.record_request_context`** is **`true`** (default), the row stores **`request_route`** (from **`_route`** when set), **`request_method`**, and **`request_path`** (**`Request::getPathInfo()`** when non-empty) for the current HTTP request (see [Configuration](CONFIGURATION.md)). **CLI** commands and other non-HTTP contexts have **no** request, so those columns stay empty. Set **`record_request_context: false`** if you prefer not to store URLs or route names (privacy).

## Text overrides (operator-edited texts)

Opt-in (`overrides.enabled`, see [CONFIGURATION — Text overrides](CONFIGURATION.md#text-overrides-database)).

### Web UI desk

`GET {path_prefix}/` lists every editable message of the default locale (filter by **group** — first two key segments, e.g. `site.footer` or `NowoUiKitBundle:loader` — and full-text **search** over key + current text), with badges for the locales that have an override. `GET {path_prefix}/edit?key=site.footer.tagline` shows one textarea per locale in CSS-only tabs plus the shipped text; saving a text equal to the shipped one (or blank) deletes that locale's override. The reset form deletes every locale's override after a confirmation checkbox. Templates: `@NowoTranslationYamlToolsBundle/text_override/{layout,index,edit}.html.twig` (override them under `templates/bundles/NowoTranslationYamlToolsBundle/text_override/`). UI strings live in the `NowoTranslationYamlToolsBundle` domain (en, es). The edit page also uses `text_override/form_theme.html.twig` (CSS-only locale tab radios).

### Form bridge (host forms)

`TranslationOverrideEditor` binds any per-locale field (e.g. an unmapped field with one child per locale) to a message:

```php
use Nowo\TranslationYamlToolsBundle\TextOverride\TranslationOverrideEditor;

// prefill: override, else shipped text, per locale
$form->get('intro')->setData($editor->currentTexts('messages', 'site.specialty.intro'));

// on submit (same transaction as your entity)
$editor->save('messages', 'site.specialty.intro', (array) $form->get('intro')->getData(), flush: false, updatedBy: $user?->getUserIdentifier());
$entityManager->flush();
$editor->flush(); // flushes + invalidates the cached map
```

Other helpers: `locales()`, `defaultLocale()`, `shippedTexts()`, `overriddenLocales()`, `reset()`. Low-level reads/writes: `TranslationOverrides::get()/all()/set()/invalidate()`; read-only consumers can type-hint `TranslationOverrideProviderInterface`.

### HTML sanitizing

Values containing `<` go through `TranslationOverrideHtmlSanitizerInterface` on save and again when the map is (re)loaded from the database. Default: inline allowlist (`a[href|title|target]` with forced `rel="noopener noreferrer"`, `strong`, `b`, `em`, `i`, `u`, `s`, `small`, `code`, `abbr`, `span[class]`, `br`; http/https/mailto/tel and relative links) when `symfony/html-sanitizer` is installed, else every tag is stripped. Plug your own:

```php
final class AppTextSanitizer implements TranslationOverrideHtmlSanitizerInterface
{
    public function __construct(private HtmlSanitizerInterface $appSanitizer) {}
    public function sanitize(string $html): string { return $this->appSanitizer->sanitize($html); }
}
```

```yaml
nowo_translation_yaml_tools:
    overrides:
        html_sanitizer: App\Text\AppTextSanitizer
```

## Bundle translations: locales and overrides

The text-overrides Web UI labels use the translation domain **`NowoTranslationYamlToolsBundle`** (REQ-I18N-003). The bundle ships **`src/Resources/translations/NowoTranslationYamlToolsBundle.<locale>.yaml`** for **en** (reference), **es**, **it**, **fr**, **pt**, **de** and **nl** (REQ-I18N-002); every locale has exactly the keys of `en`, checked by `make validate-translations` and by `tests/Unit/Resources/TranslationCataloguesTest.php`. Other locales fall back to your `framework.translator.fallbacks`.

**Override a label (app wins, bundle is the fallback — REQ-I18N-001):**

1. Use the same domain, `NowoTranslationYamlToolsBundle`.
2. Create **`translations/NowoTranslationYamlToolsBundle.<locale>.yaml`** in your app (your `translator.default_path`, usually `translations/`).
3. Put only the keys you want to change; any key you leave out keeps the bundle text.
4. Clear the cache: `php bin/console cache:clear`.

Example — `translations/NowoTranslationYamlToolsBundle.es.yaml`:

```yaml
overrides:
    title: Textos editables
    save: Guardar cambios
    flash:
        saved: Cambios guardados.
```

To add a locale the bundle does not ship (e.g. `ca`), create `translations/NowoTranslationYamlToolsBundle.ca.yaml` with the keys from the `en` catalogue. Application translation files are loaded after bundle files, so the same key in your app always takes priority.

## Overriding templates (REQ-TWIG-001)

The bundle registers the Twig namespace **`@NowoTranslationYamlToolsBundle/`** only when the Web UI is enabled. **`TwigPathsPass`** maps the Symfony override directory **`templates/bundles/NowoTranslationYamlToolsBundle/`** to that namespace with **`prependPath()`** when the folder exists, then registers the bundle **`src/Resources/views`** path with **`addPath()`**, so your app copies are tried before the vendor templates. You do not need entries in **`config/packages/twig.yaml`** for this.

**Freeze rule:** a full-file override hides vendor updates for that `<subpath>` until you delete or manually merge it. Prefer **`missing_translation_log.web_ui.layout_template`** / **`css_framework`** (and host / bridge layouts) over copying every missing-log page; override only the one file you customise.

**Procedure (app):**

1. Take the template path relative to the bundle views root (the **`<subpath>`** below).
2. Create **`templates/bundles/NowoTranslationYamlToolsBundle/<subpath>`** in your project (same relative path).
3. Clear the Twig / Symfony cache in dev if needed: **`php bin/console cache:clear`**.

**Example:** to override the list page shell, copy from the bundle and edit:

```text
templates/bundles/NowoTranslationYamlToolsBundle/missing_translation_log/index.html.twig
```

**Templates you can override:**

| Subpath | Purpose |
|--------|---------|
| `missing_translation_log/layout.html.twig` | Default Bootstrap shell (navbar, main, blocks `missing_log_title`, `nowo_translation_yaml_tools_missing_log_content`, etc.). |
| `missing_translation_log/base.html.twig` | Extends the configurable layout; flashes and blocks `missing_log_breadcrumb`, `missing_log_body`. |
| `missing_translation_log/index.html.twig` | Missing-log list page; blocks for heading, filters, table. |
| `missing_translation_log/_status_filters.html.twig` | Pending / Added / Validated pill links. |
| `missing_translation_log/_table.html.twig` | Data table and “Mark added” forms (`mark_added_forms[row.id]`). |
| `missing_translation_log/layout_integrate_dashboard_menu.html.twig` | Bridge extending **NowoDashboardMenuBundle** dashboard layout (optional). |
| `missing_translation_log/layout_integrate_breadcrumb_kit.html.twig` | Bridge extending **NowoBreadcrumbKitBundle** dashboard layout (optional). |

**Layout without copying files:** set **`missing_translation_log.web_ui.layout_template`** to your app layout or to one of the bridge templates above; the Twig global **`nowo_translation_yaml_tools_missing_log_layout_template`** mirrors that value for use in custom templates. The page shell (`base.html.twig`) stacks `stylesheets` / `javascripts` with `{{ parent() }}` (REQ-UI-001).

**`css_framework` (REQ-UI-001):** set **`missing_translation_log.web_ui.css_framework`** to match the host stack (`bootstrap5` default, or `tailwind` / `foundation` / `custom` / …). Twig global: **`nowo_translation_yaml_tools_css_framework`**. When using a host / DashboardMenu / BreadcrumbKit layout, set `custom` (or match that shell) so the standalone Bootstrap CDN is not assumed.

```yaml
nowo_translation_yaml_tools:
    missing_translation_log:
        web_ui:
            enabled: true
            css_framework: bootstrap5
            # layout_template: '@NowoTranslationYamlToolsBundle/missing_translation_log/layout_integrate_dashboard_menu.html.twig'
```

## Symfony 8 demo

From `demo/symfony8` (with the stack up): **`make translation-yaml-demos`** runs `tree`, `sort`, `flatten`, and `fill-missing` in **dry-run** on sample domains; **`make translation-yaml-inline-preview`** applies `sort --inline` to `messages.en.yaml`, prints the file, then restores it; **`make translation-yaml-walkthrough`** also exercises **`flatten`**. The aggregate `demo/Makefile` exposes the matching `*-all` targets.

The repository includes a **FrankenPHP** demo under `demo/symfony8` with:

- `nowo-tech/twig-inspector-bundle` (dev/test)
- `framework.enabled_locales`, `framework.default_locale`, and `framework.translator.paths` configured so you can **see** resolved directories, domains, and intentional gaps on the home page
- `make validate-translations` in each demo (YAML lint on `translations/` and `translations_extra/`)

See [demo/README.md](../demo/README.md) and [DEMO-FRANKENPHP.md](DEMO-FRANKENPHP.md).
