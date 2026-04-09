# Changelog

All notable changes to this project are documented in this file.

## [Unreleased]

## [0.2.0] - 2026-04-09

### Added

- **`machine_translator_by_locale`**: choose **google**, **deepl**, or **libretranslate** per Symfony locale (target locale first, then source, then default `machine_translator`); `MachineTranslatorInterface` is implemented by **`RoutingMachineTranslator`** wiring all three backends.
- **`machine_translation_locale_map`**: map Symfony locales to exact API language codes for **Google**, **DeepL**, and **LibreTranslate** (`fill-missing`); keys match case-insensitively with `-` / `_` equivalent (e.g. `pt_BR` → configured `pt-br`).
- Console command **`nowo:translation-yaml:audit`**: read-only audit (tree convertibility with `leaf_and_prefix` conflict counts, recursive alphabetical sort, missing keys vs source locale); one-line **OK** per domain when all locales pass; non-zero exit if any issue.

## [0.1.0] - 2026-04-09

### Added

- Symfony bundle for YAML translation workflows: path discovery from `translator.default_path` and `framework.translator` config.
- Console commands: **`nowo:translation-yaml:tree`**, **`sort`**, **`flatten`**, **`fill-missing`** (interactive domain/locale selection, `--dry-run`, `--inline`, optional `--tree` on fill-missing).
- **Machine translation** via `MachineTranslatorInterface`: **Google Cloud Translation** (v2 REST), **DeepL**, and **LibreTranslate** (no paid key required on open instances; subject to server limits).
- Bundle configuration: `default_locale`, `yaml_tree_indent`, `machine_translator`, `deepl_endpoint`, `libretranslate_base_url`, `libretranslate_api_key`.
- PHPUnit suite (unit, functional, integration); `symfony/translation` as a dev dependency for the test kernel.
- Symfony **7** and **8** FrankenPHP demos (Web Profiler, Twig Inspector, dual translation paths, web insights for locales and missing keys).

### Changed

- `tree`, `sort`, and `fill-missing` print **leaf key counts** before and after structural transforms and **refuse to write** if flattening the result does not match the expected leaf map.
- `fill-missing`: clearer output (active translator, dry-run hint), progress bar when translating many keys (TTY), per-key errors with non-zero exit, final summary line.
- Commands accept **`--inline`** for compact YAML flow style; demo Makefiles include translation-yaml demo and walkthrough targets.
- Machine translators use autowired **`HttpClientInterface`** (requires `framework.http_client: true` in the app).
- Demo `README` and **contributing** docs: user-facing documentation is **English-only** (`docs/CONTRIBUTING.md`).
