# Usage

## Commands overview

| Command | Purpose |
|---------|---------|
| `nowo:translation-yaml:tree` | After listing **domains** and letting you pick **domain** and **locale** (interactive) or via flags, validates that dot-keys can be turned into a nested tree; writes nested YAML using `yaml_tree_indent`. |
| `nowo:translation-yaml:flatten` | Same selection pattern; flattens nested maps to a **single-level** map whose keys are **dot paths** (e.g. `demo.title`)—the inverse layout of `tree`. |
| `nowo:translation-yaml:sort` | Same selection pattern; sorts associative keys recursively. |
| `nowo:translation-yaml:fill-missing` | Uses the **default source locale** (see [Configuration](CONFIGURATION.md)) and a **target locale** file; translates missing keys via the configured backend (**Google**, **DeepL**, or **LibreTranslate**). |

All four write commands accept **`--inline`**: dump translations as compact **YAML flow style** (e.g. `{ demo: { title: … } }` or `{ demo.title: … }`) instead of expanded blocks and multi-line literals.

## Interactive selection

Run without `--domain` / `--locale` (where applicable) in a TTY: the commands print discovered directories, list **domains** from files named `domain.locale.yaml`, then prompt with **choice lists** (arrow keys in most terminals).

Use `--no-interaction` together with explicit options for CI or scripts.

## Examples

Dry-run tree conversion:

```bash
php bin/console nowo:translation-yaml:tree --domain=messages --locale=en --dry-run
```

Apply nested tree:

```bash
php bin/console nowo:translation-yaml:tree --domain=messages --locale=en
```

Sort keys:

```bash
php bin/console nowo:translation-yaml:sort --domain=messages --locale=en
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

Fill missing Spanish keys from English (default locale):

```bash
php bin/console nowo:translation-yaml:fill-missing --domain=messages --target-locale=es
```

Fill and write nested YAML:

```bash
php bin/console nowo:translation-yaml:fill-missing --domain=messages --target-locale=es --tree
```

## When tree conversion is impossible

If a **leaf key** is also a **prefix** of another key (for example both `a` and `a.b` as distinct leaves after flattening), nested YAML cannot represent the same map unambiguously. The `tree` command (and `fill-missing --tree`) stops and prints which prefix causes the conflict.

## File naming

Files must follow Symfony’s usual pattern:

`translations/<domain>.<locale>.yaml` (or `.yml`).

## Symfony 7 / 8 demos

From `demo/symfony7` or `demo/symfony8` (with the stack up): **`make translation-yaml-demos`** runs `tree`, `sort`, `flatten`, and `fill-missing` in **dry-run** on sample domains; **`make translation-yaml-inline-preview`** applies `sort --inline` to `messages.en.yaml`, prints the file, then restores it; **`make translation-yaml-walkthrough`** also exercises **`flatten`**. The aggregate `demo/Makefile` exposes the matching `*-all` targets.

The repository includes **FrankenPHP** demos under `demo/symfony7` and `demo/symfony8` with:

- `nowo-tech/twig-inspector-bundle` (dev/test)
- `framework.enabled_locales`, `framework.default_locale`, and `framework.translator.paths` configured so you can **see** resolved directories, domains, and intentional gaps on the home page
- `make validate-translations` in each demo (YAML lint on `translations/` and `translations_extra/`)

See [demo/README.md](../demo/README.md) and [DEMO-FRANKENPHP.md](DEMO-FRANKENPHP.md).
