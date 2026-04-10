# Symfony 8.0 demo — Translation YAML Tools Bundle

Same setup as the Symfony 7 demo: FrankenPHP, Web Profiler, **Twig Inspector**, dual translation paths, and deliberate gaps for `en` / `es` / `fr`.

Default **PORT** in `.env.example`: **8038**.

**Missing translation log (dev):** same as the Symfony 7 demo — SQLite **`demo_translation_missing_log`**, **`async_persist` + `event_dispatcher`** (no Messenger), Web UI at **`/_demo/translation-yaml-tools/missing-log`**; `make up` runs `doctrine:schema:update`.

## Quick start

```bash
cp .env.example .env   # if needed
make up
```

Open the URL printed by `make up` (`Demo started at: http://localhost:<PORT>`).

## From the host (Make)

Targets run `docker-compose` for you; you do not need a shell in the container first.

| Target | Purpose |
|--------|---------|
| `make validate-translations` | `lint:yaml` on `translations/` and `translations_extra/` |
| `make translation-yaml-demos` | `tree`, `sort`, `flatten`, and `fill-missing` in **dry-run** (messages + validators) |
| `make translation-yaml-inline-preview` | `sort --inline` on `messages.en.yaml`, prints the file, then restores it |
| `make translation-yaml-walkthrough` | Runs **all** useful variants with pauses (default `PAUSE=3`; e.g. `PAUSE=5`) and **restores** `translations/` at the end |
| `make shell` | Interactive shell inside the `php` service |
| `make missing-log-schema` | `doctrine:schema:update --force` (creates/updates **`demo_translation_missing_log`**) |

## Inside the container (`docker-compose exec`)

The project in the container is at **`/app`**. Symfony console:

```bash
docker-compose exec php php bin/console <command>
```

For non-interactive scripts you can use **`exec -T`** (no TTY).

### Translation YAML Tools bundle (`nowo:translation-yaml:*`)

Replace `messages` / `en` / `es` with your domain and locales.

**Nested tree (dot keys → nested YAML)**

```bash
docker-compose exec php php bin/console nowo:translation-yaml:tree --domain=messages --locale=en --dry-run
docker-compose exec php php bin/console nowo:translation-yaml:tree --domain=messages --locale=en
docker-compose exec php php bin/console nowo:translation-yaml:tree --domain=messages --locale=en --inline
```

**Sort keys (recursive)**

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

**Fill missing keys** (requires a configured API: Google or DeepL; see `config/packages/translation_yaml_tools.yaml` and `.env`)

```bash
docker-compose exec php php bin/console nowo:translation-yaml:fill-missing --domain=messages --target-locale=es --dry-run
docker-compose exec php php bin/console nowo:translation-yaml:fill-missing --domain=messages --target-locale=es
docker-compose exec php php bin/console nowo:translation-yaml:fill-missing --domain=messages --target-locale=es --tree
docker-compose exec php php bin/console nowo:translation-yaml:fill-missing --domain=messages --target-locale=es --tree --inline
```

Optional: `--source-locale=xx` if you do not want the application default source locale.

### Other useful commands in this demo

```bash
docker-compose exec php php bin/console lint:yaml translations
docker-compose exec php php bin/console cache:clear
```

**Interactive mode** (choose domain/locale with arrows): use a TTY — `docker-compose exec php php bin/console nowo:translation-yaml:tree` without `--domain` / `--locale`.

Bundle docs: `docs/USAGE.md` and `docs/CONFIGURATION.md` at the bundle repository root.

See `docs/DEMO-FRANKENPHP.md` at the bundle root for FrankenPHP notes.
