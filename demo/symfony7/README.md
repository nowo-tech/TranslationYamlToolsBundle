# Symfony 7.0 demo — Translation YAML Tools Bundle

FrankenPHP + Web Profiler + **Twig Inspector** + YAML translation demos.

- **Default locale:** `en` (`framework.default_locale`)
- **Enabled locales:** `en`, `es`, `fr` (`framework.enabled_locales`)
- **Paths:** `translations/` + `translations_extra/` via `framework.translator.paths`
- **Gaps:** deliberate missing keys / no `fr` files so you can try the insights page and bundle commands

## Quick start

```bash
cp .env.example .env   # if needed
make up
```

Open the URL printed by `make up` (`Demo started at: http://localhost:<PORT>`; default **8037**).

## From the host (Make)

Targets invoke `docker-compose` for you.

| Target | Purpose |
|--------|---------|
| `make validate-translations` | `lint:yaml` on `translations/` and `translations_extra/` |
| `make translation-yaml-demos` | `tree`, `sort`, `flatten`, and `fill-missing` in **dry-run** (messages + validators) |
| `make translation-yaml-inline-preview` | `sort --inline` on `messages.en.yaml`, prints the file, then restores it |
| `make translation-yaml-walkthrough` | Runs all useful variants with pauses between steps (default `PAUSE=3`; e.g. `make translation-yaml-walkthrough PAUSE=5`); **restores** `translations/` at the end |
| `make shell` | Interactive shell in the `php` service |

## Inside the container (`docker-compose exec`)

The demo project in the container lives at **`/app`**. Symfony console:

```bash
docker-compose exec php php bin/console <command>
```

For script-friendly output, use **`docker-compose exec -T`** (no TTY).

### Translation YAML Tools bundle (`nowo:translation-yaml:*`)

Replace `messages`, `en`, or `es` with the domain and locales you need.

**Nested tree** (dot keys → nested YAML)

```bash
docker-compose exec php php bin/console nowo:translation-yaml:tree --domain=messages --locale=en --dry-run
docker-compose exec php php bin/console nowo:translation-yaml:tree --domain=messages --locale=en
docker-compose exec php php bin/console nowo:translation-yaml:tree --domain=messages --locale=en --inline
```

**Sort keys** (associative, recursive)

```bash
docker-compose exec php php bin/console nowo:translation-yaml:sort --domain=messages --locale=en --dry-run
docker-compose exec php php bin/console nowo:translation-yaml:sort --domain=messages --locale=en
docker-compose exec php php bin/console nowo:translation-yaml:sort --domain=messages --locale=en --inline
```

**Flatten to dot keys** (nested maps → single level, e.g. `demo.title`)

```bash
docker-compose exec php php bin/console nowo:translation-yaml:flatten --domain=messages --locale=en --dry-run
docker-compose exec php php bin/console nowo:translation-yaml:flatten --domain=messages --locale=en
docker-compose exec php php bin/console nowo:translation-yaml:flatten --domain=messages --locale=en --inline
```

**Fill missing keys** (Google or DeepL per config; API keys in `.env`)

```bash
docker-compose exec php php bin/console nowo:translation-yaml:fill-missing --domain=messages --target-locale=es --dry-run
docker-compose exec php php bin/console nowo:translation-yaml:fill-missing --domain=messages --target-locale=es
docker-compose exec php php bin/console nowo:translation-yaml:fill-missing --domain=messages --target-locale=es --tree
docker-compose exec php php bin/console nowo:translation-yaml:fill-missing --domain=messages --target-locale=es --tree --inline
```

Optional: `--source-locale=xx` if the source locale should not be the application default.

Same pattern with **`--domain=validators`** and the right locale.

### Other useful commands in this demo

```bash
docker-compose exec php php bin/console lint:yaml translations
docker-compose exec php php bin/console cache:clear
```

**Interactive mode** (pick domain and locale in the terminal): `docker-compose exec php php bin/console nowo:translation-yaml:tree` without `--domain` / `--locale` (requires a TTY).

More detail: `docs/USAGE.md` and `docs/CONFIGURATION.md` at the bundle root.

See `docs/DEMO-FRANKENPHP.md` for FrankenPHP notes (dev vs prod).
