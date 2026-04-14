# Changelog

All notable changes to this project are documented in this file.

## [Unreleased]

### Fixed

- **Missing translation log:** **`persistBuffer`** uses Doctrine **class metadata** for physical column names in DBAL **`INSERT`/`UPDATE`** (correct when the default naming strategy maps fields such as **`hitCount`** to **`hitCount`**, not **`hit_count`**).

### Changed

- **`require-dev`:** **`symfony/var-exporter`** is constrained to **`^6.4 || ^7.0`** (Symfony **8**’s **`var-exporter`** drops **`ProxyHelper::generateLazyGhost`**, which Doctrine ORM still expects when **`enable_native_lazy_objects`** is **`false`**, breaking **`composer test`** on PHP **8.4+**).

### Tests

- **`TranslationYamlCommandsTest`:** **`createDeps`** creates **`translations/`** only when missing (avoids **`mkdir(): File exists`** when a test already created that directory).
- **`MissingTranslationLogWebUiTest`:** **`setUp`** calls **`ensureKernelShutdown()`** before **`createClient()`** so a kernel leaked from another test does not trigger **“Booting the kernel before calling createClient() is not supported”**.

## [0.3.2] - 2026-04-14

### Fixed

- **Missing translation log:** **`MissingTranslationLogRepository::persistBuffer()`** writes rows with DBAL **`INSERT`** and, on **unique constraint violation** (same **`message_id`**, **`domain`**, **`locale`**), runs **`UPDATE`** to add **`hit_count`**, refresh **`last_seen_at`**, and optionally **`call_site`**. Avoids **`SQLSTATE[23000]`** / MySQL **1062** duplicate-entry errors when two requests or workers try to create the same missing-key row at once (the previous **`findOneBy` + ORM `persist`** path was not safe under concurrency).

### Tests

- **Integration:** repeated flush for the same missing key keeps **one** row and increments **`hit_count`**.

### Documentation

- **[CONFIGURATION](CONFIGURATION.md):** unique key **`(message_id, domain, locale)`** and **0.3.2+** persist behaviour.
- **[USAGE](USAGE.md):** missing-log Web UI section — concurrency / duplicate-safe flushes.
- **[UPGRADING](UPGRADING.md):** **0.3.1 → 0.3.2** for **`missing_translation_log`** users.

## [0.3.1] - 2026-04-10

### Fixed

- **Twig (Web UI):** **`TwigPathsPass`** registers **`@NowoTranslationYamlToolsBundle/`** with **`prependPath()`** for **`templates/bundles/NowoTranslationYamlToolsBundle/`** when that directory exists, then **`addPath()`** for **`src/Resources/views`** (Composer package root), so app overrides win without **`twig.paths`** (**REQ-TWIG-001**). **0.3.0** pointed at a non-existent **`Resources/views`** directory at the package root, which made **`bin/console cache:clear`** fail while Twig warmed the **`FilesystemLoader`**.

### Documentation

- **[USAGE](USAGE.md):** Web UI and **“Overriding templates (REQ-TWIG-001)”** (procedure, subpath table, **`prependPath` / `addPath`**).
- **[CONFIGURATION](CONFIGURATION.md):** **`web_ui`** row; Twig registration via **`TwigPathsPass`** and **`src/Resources/views`**.
- **[INSTALLATION](INSTALLATION.md):** link to template override procedure.
- **[UPGRADING](UPGRADING.md):** **0.3.0 → 0.3.1** notes for Web UI users.

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
