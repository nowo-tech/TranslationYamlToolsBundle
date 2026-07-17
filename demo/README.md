# Demos — Translation YAML Tools Bundle

FrankenPHP demo:

| Demo       | Symfony | Default PORT (`.env.example`) |
|-----------|---------|-------------------------------|
| `symfony8` | 8.1     | 8038                          |

Each demo includes:

- **Web Profiler** + **Twig Inspector** (`nowo-tech/twig-inspector-bundle`, dev/test)
- **Translation** setup with `framework.default_locale`, `framework.enabled_locales` (`en`, `es`, `fr`), `translator.paths` for a second directory (`translations_extra/`)
- A **web insights** page at `/` showing default locale, enabled locales, YAML paths, missing locale files, and missing keys vs the default locale
- **Dev-only** **`missing_translation_log`**: Doctrine + SQLite, **Web UI** at `/_demo/translation-yaml-tools/missing-log`, **`async_persist_strategy: event_dispatcher`** (no `symfony/messenger`); `make up` starts from a fresh DB (removes `var/demo_missing_log.sqlite`) and recreates schema. You can also run `make reset-db` inside each demo.
- **SecurityBundle** (required for the Web UI): form login at `/login`, firewall + `access_control` for `/_demo/translation-yaml-tools`, bundle `required_role: ROLE_ADMIN`. Demo credentials: **`admin` / `admin`** (in-memory, plaintext hasher — local demos only). No `allow_unauthenticated`.
- Missing-log playground routes under `/missing-log/probes/{scenario}` with a menu for repeatable scenarios:
  - `twig`
  - `domain-locale`
  - `repeat-hits`
- The insights/playground pages intentionally trigger missing ids from Twig and PHP so you can compare **`call_site`** (backtrace) plus **`request_route` / `request_method` / `request_path`** in the Web UI or `nowo:translation-yaml:missing-log-list` — see **`docs/USAGE.md`** (“Missing translation log: coverage and call_site”).
- Sample YAML gaps (no `messages.fr.yaml`, missing keys in `es`, etc.) to exercise the bundle commands

## Commands

```bash
make -C demo help
make -C demo up-symfony8
make -C demo test-all
make -C demo validate-translations
make -C demo release-check
```

See [DEMO-FRANKENPHP.md](../docs/DEMO-FRANKENPHP.md) for FrankenPHP dev vs prod.
