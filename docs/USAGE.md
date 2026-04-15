# Usage

## Table of contents

- [Commands overview](#commands-overview)
- [Interactive selection](#interactive-selection)
- [Examples](#examples)
- [When tree conversion is impossible](#when-tree-conversion-is-impossible)
- [File naming](#file-naming)
- [Twig — missing translation log Web UI](#twig--missing-translation-log-web-ui)
- [Overriding templates (REQ-TWIG-001)](#overriding-templates-req-twig-001)
- [Symfony 7 / 8 demos](#symfony-78-demos)

## Commands overview

| Command | Purpose |
|---------|---------|
| `nowo:translation-yaml:tree` | Pick **domain** (and optionally **locale**); validates that dot-keys can be turned into a nested tree; writes nested YAML using `yaml_tree_indent`. Omit **`--locale`** to convert **every** locale file for the domain. Optional **`--fix-leaf-prefix`** resolves leaf/prefix conflicts (see [When tree conversion is impossible](#when-tree-conversion-is-impossible)). |
| `nowo:translation-yaml:flatten` | Same pattern; flattens nested maps to a **single-level** map whose keys are **dot paths** (e.g. `demo.title`). Omit **`--locale`** to flatten **all** locale files for the domain. |
| `nowo:translation-yaml:sort` | Same pattern; sorts associative keys recursively. Omit **`--locale`** to sort **all** locale files for the domain. |
| `nowo:translation-yaml:fill-missing` | Uses the **default source locale** (see [Configuration](CONFIGURATION.md)) and a **target locale** file; translates missing keys via the configured backend (**Google**, **DeepL**, or **LibreTranslate**). |
| `nowo:translation-yaml:audit` | Read-only **audit** of all domains (or `--domain`): tree convertibility (with `leaf_and_prefix` conflict counts and samples), recursive **alphabetical** key order, and **missing keys** vs `--source-locale` (default: Symfony default). Domains that pass every check show **one** summary line (no per-locale breakdown). Exits **non-zero** if any domain has issues. |

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
```

Fill and write nested YAML:

```bash
php bin/console nowo:translation-yaml:fill-missing --domain=messages --target-locale=es --tree
```

## When tree conversion is impossible

If a **leaf key** is also a **prefix** of another key (for example both `a` and `a.b` as distinct leaves after flattening), nested YAML cannot represent the same map unambiguously. The `tree` command (and `fill-missing --tree`) stops and prints which prefix causes the conflict — unless you pass **`--fix-leaf-prefix`**, which renames those leaves by appending **`.{suffix}`** (default suffix from **`yaml_tree_leaf_prefix_suffix`**, default **`index`**; override with **`--leaf-prefix-suffix=`**). If the target key already exists, the command reports an error.

## File naming

Files must follow Symfony’s usual pattern:

`translations/<domain>.<locale>.yaml` (or `.yml`).

## Twig — missing translation log Web UI

When **`missing_translation_log.web_ui.enabled`** is **`true`**, the bundle exposes HTML under the imported route prefix (see [Configuration](CONFIGURATION.md)). The controller renders **`@NowoTranslationYamlToolsBundle/missing_translation_log/index.html.twig`**. Optional layout integration with **NowoDashboardMenuBundle** / **NowoBreadcrumbKitBundle** uses the bridge templates and **`web_ui.layout_template`** (see [Configuration](CONFIGURATION.md)).

The log table enforces one row per **`(message_id, domain, locale)`**; from **0.3.2** onward, flushes use duplicate-safe persistence so parallel traffic does not hit SQL duplicate-key errors (details in [Configuration](CONFIGURATION.md#missing-translation-log-database)).

## Overriding templates (REQ-TWIG-001)

The bundle registers the Twig namespace **`@NowoTranslationYamlToolsBundle/`** only when the Web UI is enabled. **`TwigPathsPass`** maps the Symfony override directory **`templates/bundles/NowoTranslationYamlToolsBundle/`** to that namespace with **`prependPath()`** when the folder exists, then registers the bundle **`src/Resources/views`** path with **`addPath()`**, so your app copies are tried before the vendor templates. You do not need entries in **`config/packages/twig.yaml`** for this.

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
| `missing_translation_log/_table.html.twig` | Data table and “Mark added” forms. |
| `missing_translation_log/layout_integrate_dashboard_menu.html.twig` | Bridge extending **NowoDashboardMenuBundle** dashboard layout (optional). |
| `missing_translation_log/layout_integrate_breadcrumb_kit.html.twig` | Bridge extending **NowoBreadcrumbKitBundle** dashboard layout (optional). |

**Layout without copying files:** set **`missing_translation_log.web_ui.layout_template`** to your app layout or to one of the bridge templates above; the Twig global **`nowo_translation_yaml_tools_missing_log_layout_template`** mirrors that value for use in custom templates.

## Symfony 7 / 8 demos

From `demo/symfony7` or `demo/symfony8` (with the stack up): **`make translation-yaml-demos`** runs `tree`, `sort`, `flatten`, and `fill-missing` in **dry-run** on sample domains; **`make translation-yaml-inline-preview`** applies `sort --inline` to `messages.en.yaml`, prints the file, then restores it; **`make translation-yaml-walkthrough`** also exercises **`flatten`**. The aggregate `demo/Makefile` exposes the matching `*-all` targets.

The repository includes **FrankenPHP** demos under `demo/symfony7` and `demo/symfony8` with:

- `nowo-tech/twig-inspector-bundle` (dev/test)
- `framework.enabled_locales`, `framework.default_locale`, and `framework.translator.paths` configured so you can **see** resolved directories, domains, and intentional gaps on the home page
- `make validate-translations` in each demo (YAML lint on `translations/` and `translations_extra/`)

See [demo/README.md](../demo/README.md) and [DEMO-FRANKENPHP.md](DEMO-FRANKENPHP.md).
