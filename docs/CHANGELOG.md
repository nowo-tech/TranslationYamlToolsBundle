# Changelog

All notable changes to this project are documented in this file.

## [Unreleased]

### Fixed

- **Twig (Web UI):** **`TwigPathsPass`** registers **`@NowoTranslationYamlToolsBundle/`** with **`prependPath()`** for **`templates/bundles/NowoTranslationYamlToolsBundle/`** when that directory exists, then **`addPath()`** for **`src/Resources/views`** (Composer package root), so app overrides win without **`twig.paths`** (**REQ-TWIG-001**). Earlier builds pointed at a non-existent **`Resources/views`** at the package root and broke **`cache:clear`** when warming Twig.

### Documentation

- **[USAGE](USAGE.md):** Twig Web UI note and **“Overriding templates (REQ-TWIG-001)”** with subpath table and procedure.
- **[CONFIGURATION](CONFIGURATION.md):** **`web_ui.layout_template`** row; clarify Web UI Twig registration.
- **[INSTALLATION](INSTALLATION.md):** link to template override procedure.

## [0.3.0] - 2026-04-10

### Added

- **`missing_translation_log.enabled`**: optional runtime capture of translation lookups where the **requested locale** catalogue does not define the key; persists to Doctrine entity **`MissingTranslationLog`** with statuses **`pending`**, **`added`**, **`validated`**; decorates **`translator`** and flushes buffered hits on **`kernel.terminate`**.
- **`missing_translation_log.table_prefix`**: physical table **`{prefix}missing_log`** (default **`nowo_translation_missing_log`**), applied via **`MissingTranslationLogMetadataListener`**.
- **`missing_translation_log.record_call_site`** and DB column **`call_site`**: optional caller **`path:line`** via **`TranslationCallSiteResolver`** (backtrace); disable with **`record_call_site: false`**.
- **`missing_translation_log.async_persist`** and **`async_persist_strategy`** (`messenger` \| `event_dispatcher`): when **`async_persist`** is **`true`**, flush delegates by strategy — **`MissingTranslationBufferMessage`** + Messenger handler, or **`MissingTranslationBufferEvent`** + optional builtin **`MissingTranslationBufferDoctrinePersistListener`** (skipped if a listener called **`stopPropagation()`**). Without Messenger / bus / **`event_dispatcher`**, the recorder falls back to synchronous **`persistBuffer`**.
- **`missing_translation_log.web_ui`**: optional Twig UI (**list** + **Mark added** POST with CSRF); routes in **`Resources/config/routes/missing_translation_log_ui.yaml`**; Flex recipe adds a **dev** route import; bundle **prepends** Twig paths when enabled.
- Console commands **`nowo:translation-yaml:missing-log-list`**, **`missing-log-mark-added`**, **`missing-log-validate`** (when the feature is enabled).
- Composer **`require`** **`symfony/translation`**; **`require-dev`** **`doctrine/orm`**, **`doctrine/doctrine-bundle`**, **`symfony/messenger`**, **`symfony/twig-bundle`**, **`symfony/browser-kit`**, **`symfony/security-csrf`** for tests.
- Composer **`suggest`** **`symfony/messenger`** for optional async missing-log persistence.

### Changed

- **`MissingTranslationLogRepository`** is no longer **`final`**, so PHPUnit can generate test doubles for **`persistBuffer`**.

### Fixed

- **`DoctrineMissingTranslationRecorder`**: use **`interface_exists(MessageBusInterface::class)`** (not **`class_exists`**) before the Messenger flush branch so **`async_persist_strategy: messenger`** works when a bus is present.

### Demos

- **Symfony 8**: **`doctrine/doctrine-bundle` ^3.2** (Symfony 8–compatible); SQLite-only **`docker-compose`**; ignore Flex **`compose.override.yaml`** (documented in **`.gitignore`**); Framework / WebProfiler route imports use **`.php`** loaders; **`TranslationInsightsBuilder`** falls back to **`kernel.default_locale`** when **`translator.default_locale`** is absent; Twig **`verbatim`** around literal **`{% trans %}`** in the insights template.
- **Symfony 7**: aligned Compose / env with SQLite demo; refreshed **`composer.lock`** for Doctrine and CSRF.

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
