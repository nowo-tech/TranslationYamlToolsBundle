# Changelog

All notable changes to this project are documented in this file.

## [Unreleased]

### Added

- **LibreTranslate** machine translator (`machine_translator: libretranslate`, optional `libretranslate_base_url` / `libretranslate_api_key`) for no-cost translation on public or self-hosted instances (subject to server rate limits).
- Console command **`nowo:translation-yaml:flatten`**: flatten nested translation YAML to dot-path keys at the root (`--dry-run`, `--inline`, same leaf-key integrity checks as `sort` / `tree`).
- PHPUnit suite (unit, functional, integration) targeting **100% line coverage** on production code (excluding `MachineTranslatorInterface`); `symfony/translation` as a **dev** dependency for the test kernel.
- **DeepL** machine translator (`machine_translator: deepl`, `deepl_endpoint`, env `DEEPL_AUTH_KEY`) implementing `MachineTranslatorInterface`.
- Symfony **7** and **8** FrankenPHP demos with Web Profiler, **Twig Inspector**, dual translation paths, and a web insights page for locales / YAML locations / missing keys.
- Initial release: translation path discovery, `tree`, `sort`, and `fill-missing` commands, Google-backed `MachineTranslatorInterface`, and bundle configuration (`default_locale`, `yaml_tree_indent`, `machine_translator`).

### Changed

- Demo `README.md` files and **contributing** guidelines: user-facing documentation is **English-only** (`docs/CONTRIBUTING.md`).
- `tree`, `sort`, and `fill-missing` print **leaf key counts** before and after the structural transform and **refuse to write** if flattening the result does not match the expected leaf map (same keys and values).
- Demos: **`make translation-yaml-walkthrough`** (and aggregate `translation-yaml-walkthrough-all`) run every bundle CLI variant with configurable **`PAUSE`** seconds between steps, print `messages.en.yaml` / `validators.en.yaml` after writes, and restore `translations/` from backup at the end (`demo/scripts/translation-yaml-walkthrough.sh`).
- Commands `tree`, `sort`, and `fill-missing` accept **`--inline`** for compact YAML flow output; demo Makefiles add `translation-yaml-demos` and `translation-yaml-inline-preview` targets; demo UI shows Twig **inline** translation examples (`|trans` and `{% trans %}`).
- Machine translators now receive `HttpClientInterface` via **autowiring** (enable `framework.http_client: true` in the app, as in the demos).
- `fill-missing`: clearer copy (active translator, dry-run hint), progress bar for batch string translation (TTY), per-key errors with non-zero exit code, and final line with path and key count.
- `sort` / shared command helpers: slightly clearer error messages for missing files and non-interactive mode.
