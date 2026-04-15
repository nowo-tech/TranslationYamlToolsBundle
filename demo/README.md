# Demos — Translation YAML Tools Bundle

Two FrankenPHP demos:

| Demo       | Symfony | Default PORT (`.env.example`) |
|-----------|---------|-------------------------------|
| `symfony7` | 7.0     | 8037                          |
| `symfony8` | 8.0     | 8038                          |

Each demo includes:

- **Web Profiler** + **Twig Inspector** (`nowo-tech/twig-inspector-bundle`, dev/test)
- **Translation** setup with `framework.default_locale`, `framework.enabled_locales` (`en`, `es`, `fr`), `translator.paths` for a second directory (`translations_extra/`)
- A **web insights** page at `/` showing default locale, enabled locales, YAML paths, missing locale files, and missing keys vs the default locale
- **Dev-only** **`missing_translation_log`**: Doctrine + SQLite, **Web UI** at `/_demo/translation-yaml-tools/missing-log`, **`async_persist_strategy: event_dispatcher`** (no `symfony/messenger`); `make up` runs `doctrine:schema:update` to create **`demo_translation_missing_log`**
- The **`/`** insights page in **dev** triggers **three** intentional missing ids (`nowo_demo.missing_log_probe`, `nowo_demo.missing_from_twig_filter`, `nowo_demo.missing_from_controller`) so you can compare **`call_site`** (backtrace only: Twig vs PHP) plus **`request_route` / `request_method` / `request_path`** in the Web UI or `nowo:translation-yaml:missing-log-list` — see **`docs/USAGE.md`** (“Missing translation log: coverage and call_site”).
- Sample YAML gaps (no `messages.fr.yaml`, missing keys in `es`, etc.) to exercise the bundle commands

## Commands

```bash
make -C demo help
make -C demo up-symfony7
make -C demo test-all
make -C demo validate-translations
make -C demo release-check
```

See [DEMO-FRANKENPHP.md](../docs/DEMO-FRANKENPHP.md) for FrankenPHP dev vs prod.
