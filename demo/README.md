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
