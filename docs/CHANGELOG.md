# Changelog

All notable changes to this project are documented in this file.

## [Unreleased]

## [1.2.0] - 2026-07-17

### Security

- **Web UI deny-by-default:** enabling **`missing_translation_log.web_ui`** without **`security.authorization_checker`** now fails container compilation unless **`allow_unauthenticated: true`** (dev/demo only). Enforced in **`MissingLogWebUiSecurityPass`** (not in Extension::load — isolated merge container). New config key documented in **CONFIGURATION** / **SECURITY** / Flex recipe.
- **`MissingLogUiAccessSubscriber`:** denies access when a **`required_role`** is set but the authorization checker is unavailable (no silent open UI).
- **Controller `denyAccessUnlessGranted`:** missing-log UI actions enforce **`web_ui.required_role`** (defense in depth vs subscriber).
- **YAML bomb caps:** **`TranslationYamlFileHandler`** rejects files &gt; **2 MiB**, depth &gt; **64**, or &gt; **50 000** nodes; framework translation-config discovery skips oversized YAML.
- **`fill-missing` path hardening:** domain/locale must match **`[a-zA-Z0-9_-]+`**; new files must stay under configured translation directories (`realpath` check).
- **LibreTranslate SSRF allowlist:** **`libretranslate_allowed_hosts`** (default **`libretranslate.com`**) + optional **`libretranslate_allow_http`**; invalid base URLs fail container compilation.
- **MT rate limits / timeouts:** **`machine_translation_min_interval_ms`**, **`machine_translation_max_requests_per_run`**, **`machine_translation_http_timeout`** (default 30s); **`ThrottledMachineTranslator`** wraps the router.

### Documentation

- Rewrote **[SECURITY.md](SECURITY.md)** (HTTP UI surface, auth requirements, YAML caps).
- **[INSTALLATION.md](INSTALLATION.md)** / **[CONFIGURATION.md](CONFIGURATION.md)** / Flex recipe: SecurityBundle + `access_control` guidance.

### Demos

- **Removed `demo/symfony7`:** only the **Symfony 8** FrankenPHP demo remains (`demo/symfony8`). Aggregate Makefile / docs updated accordingly.
- **Symfony 8 demo:** requires **`symfony/security-bundle`**, in-memory **`admin`/`admin`** (`ROLE_ADMIN`), form login + `access_control` for the missing-log UI; removed **`allow_unauthenticated`**.

## [1.1.3] - 2026-07-16

### Added

- **Code of Conduct:** Contributor Covenant (**`CODE_OF_CONDUCT.md`**); linked from **`README.md`** and **`docs/CONTRIBUTING.md`**.
- **REQ-GIT-001:** local Git hooks (**.githooks/commit-msg**), **`.scripts/check-no-cursor-coauthor.sh`** / **`strip-cursor-coauthor-from-history.sh`**, Makefile targets (**`setup-hooks`**, **`check-no-cursor-coauthor`**, **`strip-cursor-coauthor-from-history`**), CI job **`git-hygiene`**, and **[GITHUB_CI.md](GITHUB_CI.md)**.
- **Tests:** broader coverage for console commands, DI extension / Twig paths, missing-log UI access, **`DotKeyTreeAnalyzer`**, **`RecordingTranslatorDecorator`**, and **`MissingTranslationRecordContext`**.

### Changed

- **`make release-check`** runs **`check-no-cursor-coauthor`** first; **`docs/RELEASE.md`** reminds to re-check before pushing the release tag.
- **Lockfile:** refreshed **`composer.lock`** (dev tools: PHP-CS-Fixer, Rector).

_No runtime or configuration changes under **`src/`**; upgrading from **1.1.2** is optional unless you track this repository’s CI, hooks, or test tree._

## [1.1.2] - 2026-06-30

### Fixed

- **Demos:** **`.gitignore`** now ignores **`/.phpunit.result.cache`** (PHPUnit 10+ result cache; previously only **`/.phpunit.cache/`** was excluded, so **`git add demo/…`** could accidentally stage the file after **`make test`**).

### Changed

- **Repository:** root **`.gitignore`** ignores **`.cursor/sandbox.json`** (local Cursor sandbox).
- **Lockfiles:** refreshed **`composer.lock`** (dev tools) and demo **`composer.lock`** trees (path bundle reference, Twig Inspector patch).

_No runtime or configuration changes under **`src/`**; upgrading from **1.1.1** is optional unless you track this repository’s demo or CI tree._

## [1.1.1] - 2026-07-10

### Fixed

- **`MissingTranslationLogRepository`:** replace deprecated **`Connection::quoteIdentifier()`** with **`quoteSingleIdentifier()`** (Doctrine DBAL 4.x; removed in 5.0).

## [1.1.0] - 2026-07-08

### Added

- **`missing_translation_log.web_ui.required_role`** (default **`ROLE_ADMIN`**): when the Web UI is enabled and **`security.authorization_checker`** exists, **`MissingLogUiAccessSubscriber`** denies access to **`nowo_translation_yaml_tools_missing_log_*`** routes unless the user is granted that role. Set **`null`** to disable bundle-level checks.
- **GitHub Spec Kit** baseline: **`.specify/`**, **`.cursor/skills/speckit-*`**, **`specs/001-baseline/`**, and **[SPEC-KIT.md](SPEC-KIT.md)** (install, structure, Cursor Agent workflow). **[SPEC-DRIVEN-DEVELOPMENT.md](SPEC-DRIVEN-DEVELOPMENT.md)** updated to three-layer model.

### Changed

- **Demos:** FrankenPHP images install **`intl`**; **`make up` / `ensure-up`** remove stray **`.env.dev`**; versioned **`.env.test`** comments; Makefile variables for **`update-deps`** include.

### Documentation

- **[CONFIGURATION.md](CONFIGURATION.md):** **`web_ui.required_role`** row.
- **[README.md](../README.md):** link to **SPEC-KIT.md**.

## [1.0.0] - 2026-06-30

First stable **1.x** release. Requires **PHP 8.2+** and **Symfony 7+** (same platform as **0.4.x**). Semver **`^1.0`**; **`^0.4`** does not receive **1.0.0**.

### Added

- **`MissingTranslationLogRepository::flush()`** and **`clearManaged()`** — public persistence helpers; commands and the missing-log Web UI controller use them instead of calling **`getEntityManager()`** from outside the repository.

### Changed

- **Stability:** **1.0.0** marks the bundle configuration, console commands, and documented extension points as stable for dev-tooling workflows (after the **0.4.0** platform bump).
- **PHPStan:** analysis at level **8** is clean on **`src/`** and **`tests/`**; **`phpstan.neon.dist`** drops duplicate extension **`includes`** (**`phpstan/extension-installer`** already registers Symfony and PHPUnit extensions).
- **`RecordingTranslatorDecorator`:** intersection typing for the inner translator, **`non-empty-string`** guards before **`record()`**, safe **`getFallbackLocales()`** delegation, **`@method`** hints for **`__call()`** forwarding (e.g. Lexik **`getFormats()`**).

### Fixed

- **`TranslationYamlAuditCommand`**, **`TranslationYamlFillMissingCommand`**, **`TranslationDefaultLocaleResolver`**, **`TwigPathsPass`**, **`NowoTranslationYamlToolsBundle::getContainerExtension()`** — static-analysis and edge-case hardening (no intended behaviour change for normal usage).
- **Demos (Symfony 7.4 / 8.1):** refreshed **`composer.lock`**; robust **`ensure-up`**; **`test`** environment (**`framework.test`**, **`KERNEL_CLASS`**, missing-log route import) so **`make release-check-demos`** passes.

### Tests

- PHPUnit fixtures and types aligned with PHPStan level **8**; repository tests use **`flush()`** / **`clearManaged()`**.

## [0.4.1] - 2026-06-30

### Fixed

- **CI:** Symfony **8.x** matrix jobs use **`--dev`** for test-only Symfony packages (avoids moving them to **`require`**). Symfony **8** runs only on **PHP 8.4+** (Symfony **8** minimum). Symfony **8** jobs bump **`doctrine/doctrine-bundle`** to **`^3.1`** (2.x does not support Symfony **8**).
- **Tests:** missing-log unit tests enable Doctrine **native lazy objects** on **PHP 8.4+** so ORM works with Symfony **8**’s **`var-exporter`** (LazyGhost removed).

_No PHP changes under **`src/`**; upgrading from **0.4.0** is optional unless you track this repository’s CI tree._

## [0.4.0] - 2026-06-30

### Changed (breaking)

- **Minimum PHP** raised to **`>=8.2`** (was **`>=8.1`**).
- **Minimum Symfony** raised to **`^7.0 || ^8.0`** (Symfony **6.x** no longer supported).
- **CI:** matrix runs PHP **8.2–8.5** with Symfony **7.0**, **7.4**, **8.0**, and **8.1** only.

### Documentation

- **[README](../README.md):** requirements and badges aligned with PHP **8.2+** and Symfony **7+**.
- **[UPGRADING](UPGRADING.md):** **0.3.x → 0.4.0** migration notes.

## [0.3.10] - 2026-06-30

### Changed

- **CI:** PHPUnit runs against a Symfony version matrix (**6.4**, **7.0**, **7.4**, **8.0**, **8.1**) in addition to PHP **8.1–8.5**; coverage job pins **PHP 8.2 + Symfony 7.4**.
- **`composer.json` (require-dev):** **`symfony/var-exporter`** constraint extended to **`^8.0`** (aligned with Symfony 8 test matrix).
- **Demos:** **`demo/symfony7`** targets Symfony **7.4** (was **7.0**); **`demo/symfony8`** targets Symfony **8.1** (was **8.0**); refreshed lockfiles.
- **Makefiles:** **`update-deps`** targets (REQ-MAKE-008) for the bundle root and demo apps.

### Documentation

- **[README](../README.md):** Symfony compatibility badge reflects **6.0+ | 7.4+ | 8.0 | 8.1+**.
- **[demo/README](../demo/README.md):** demo Symfony versions updated to **7.4** / **8.1**.

_No PHP changes under **`src/`**; upgrading the Composer package from **0.3.9** is optional unless you track this repository’s CI/demo tree._

## [0.3.9] - 2026-06-10

### Fixed

- **`RecordingTranslatorDecorator`:** forwards unknown translator methods to the inner service via **`__call()`** (e.g. LexikTranslationBundle **`getFormats()`**, **`removeLocalesCacheFiles()`**). Fixes **`lexik:translations:import`** and similar commands when **`missing_translation_log.enabled`** decorates **`translator`**.
- **`RecordingTranslatorDecorator`:** implements **`WarmableInterface`** and delegates **`warmUp()`** when the decorated translator supports it, so cache warmup still works through the decorator chain.

### Added

- **`docs/SPEC-DRIVEN-DEVELOPMENT.md`:** spec-driven development guide for product behaviour and traceability; linked from **`README.md`** and **`docs/ENGRAM.md`**.

### Tests

- **`RecordingTranslatorDecoratorTest`:** coverage for **`__call()`** forwarding and **`warmUp()`** delegation.

## [0.3.8] - 2026-05-12

### Changed (demos only)

- **Symfony 7 / 8 demo apps:** refreshed **`composer.lock`** (Doctrine ORM / persistence and Symfony contract packages) so `composer install` in **`demo/symfony7`** and **`demo/symfony8`** matches current resolved trees.
- **Symfony 8 demo:** regenerated **`config/reference.php`** for type consistency with the framework config reference (e.g. HTTP method override flags).

_No PHP changes under **`src/`**; upgrading the Composer package from **0.3.7** is optional and only matters if you track this repository’s demo tree._

## [0.3.7] - 2026-04-16

### Fixed

- **`RecordingTranslatorDecorator::__construct()`**: reordered parameters so the required **`MissingTranslationLogCallSiteBuilder $callSiteBuilder`** argument no longer appears after optional arguments. This removes PHP deprecation warnings (`Optional parameter declared before required parameter`) on recent runtimes.

### Tests

- **`RecordingTranslatorDecoratorTest`**: updated constructor calls to the new parameter order.

## [0.3.6] - 2026-04-16

### Changed

- **Missing translation log:** removed the experimental **`twig_template`** capture path and related resolver complexity. Runtime logging now stays focused on **`call_site`** (backtrace) plus optional HTTP context columns (**`request_route`**, **`request_method`**, **`request_path`**).
- **`TranslationCallSiteResolver`:** restored to a lean call-site resolver (no Twig template inference/reflection/file parsing), reducing per-hit overhead on hot **`trans()`** paths.
- **`MissingTranslationRecorderInterface::record()`** and buffer payload DTOs: dropped the optional Twig-template parameter/key; signatures and snapshot shapes are aligned again.
- **Missing-log Web UI:** removed the **Twig template** column; table now shows only fields backed by active persistence.

### Added

- **Missing-log Web UI actions:** new **Clear all rows** and **Clear current status** POST actions (CSRF-protected, with confirmation) to quickly reset test data.
- **Repository helpers:** **`clearAll()`** and **`clearByStatus()`** for efficient DB cleanup operations.
- **Demos (Symfony 7/8):** expanded missing-log playground routes and navigation menu for repeatable probe scenarios (Twig, domain/locale, repeat hits).

### Demos

- **`make up`** in both demos now starts from a fresh SQLite missing-log DB (delete file + schema update).
- New **`reset-db`** target to manually recreate the demo DB.

### Tests

- Updated unit coverage for controller/repository clear actions and for the simplified resolver / missing-log payloads after Twig-template removal.

### Documentation

- **[UPGRADING](UPGRADING.md):** **0.3.5 → 0.3.6** notes for custom recorder implementations and optional DB cleanup of deprecated **`twig_template`** columns.
- **[demo/README](../demo/README.md):** updated demo behaviour and probe coverage (fresh DB on `make up`, playground scenarios).

## [0.3.5] - 2026-04-15

### Added

- **`MissingTranslationLog`:** nullable columns **`request_route`**, **`request_method`**, **`request_path`** for HTTP context when **`record_request_context`** is **`true`** (default). **`MissingTranslationRecordContext`** + **`MissingTranslationLogCallSiteBuilder::buildContext()`** supply values for the decorator / recorder.

### Changed

- **`TranslationCallSiteResolver`:** skips **`MissingTranslationLogCallSiteBuilder`** so **`call_site`** points at the real caller (not the builder frame).
- **`call_site`** stores only the **backtrace** segment (**`file:line`**); route / method / path are no longer concatenated into that column.
- **`MissingTranslationLogCallSiteBuilder`** is no longer declared **`final`** so PHPUnit can generate test doubles for **`RecordingTranslatorDecorator`** tests.
- **`MissingTranslationRecorderInterface::record()`** adds optional **`$requestRoute`**, **`$requestMethod`**, **`$requestPath`** parameters (implementations and async buffer payloads must align).
- **`persistBuffer`** **`INSERT`/`UPDATE`** includes the new columns when present in the buffer snapshot.

### Tests

- **`MissingTranslationLogRepositoryTest`:** duplicate flush without request fields preserves existing HTTP columns; truncation of long **`request_path`**; **`EntityManager::clear()`** where needed so assertions read DB state after raw SQL updates.
- **`DoctrineMissingTranslationRecorderTest`**, **`RecordingTranslatorDecoratorTest`**, **`MissingTranslationLogCallSiteBuilderTest`**, **`TranslationCallSiteResolverTest`**, **`MissingTranslationLogEntityTest`**, **`MissingTranslationLogCommandsTest`**, **`MissingTranslationBufferDtosTest`:** coverage for **`buildContext()`**, buffer shape, and **`record()`** forwarding.

### Documentation

- **[USAGE](USAGE.md)** / **[CONFIGURATION](CONFIGURATION.md)** / **[UPGRADING](UPGRADING.md):** request context columns, **`persistBuffer`** behaviour, and **0.3.4 → 0.3.5** migration notes.
- **Demos (`demo/README.md`, Symfony 7/8 insights Twig, dev `nowo_translation_yaml_tools.yaml`):** copy aligned with split **`call_site`** vs HTTP columns.

## [0.3.4] - 2026-04-16

### Added

- **`nowo:translation-yaml:tree`:** **`--fix-leaf-prefix`** renames blocking leaves when a key is both a leaf and a prefix of another (append **`.{suffix}`**); default suffix from **`yaml_tree_leaf_prefix_suffix`** (default **`index`**); override per run with **`--leaf-prefix-suffix=`**.
- Bundle option **`yaml_tree_leaf_prefix_suffix`**: single final segment (`[a-zA-Z0-9_-]+`, no dots) used by **`--fix-leaf-prefix`** (parameter **`nowo_translation_yaml_tools.yaml_tree_leaf_prefix_suffix`**).
- **`nowo:translation-yaml:sort`**, **`flatten`**, **`tree`:** omit **`--locale`** to process **every locale file** for the chosen domain (non-interactive scripts need **`--domain` only**). **`--locale`** still limits to one file.

### Changed

- **`AbstractTranslationYamlCommand`:** **`resolveDomain()`**, **`resolveLocalesForDomainOption()`**, **`printLocalesBannerWhenOmittingLocaleOption()`** for shared domain/locale resolution; **`resolveDomainAndLocale()`** unchanged for commands that still prompt for a single locale (e.g. **`fill-missing`**).

### Documentation

- **[USAGE](USAGE.md):** optional **`--locale`**, **`--fix-leaf-prefix`** / **`yaml_tree_leaf_prefix_suffix`**.
- **[CONFIGURATION](CONFIGURATION.md):** **`yaml_tree_leaf_prefix_suffix`**.
- **[UPGRADING](UPGRADING.md):** **0.3.3 → 0.3.4**.
- **[README](README.md):** commands and coverage note.

## [0.3.3] - 2026-04-15

### Fixed

- **Missing translation log:** **`persistBuffer`** uses Doctrine **class metadata** for physical column names in DBAL **`INSERT`/`UPDATE`** (correct when the default naming strategy maps fields such as **`hitCount`** to **`hitCount`**, not **`hit_count`**).

### Changed

- **`require-dev`:** **`symfony/var-exporter`** is constrained to **`^6.4 || ^7.0`** (Symfony **8**’s **`var-exporter`** drops **`ProxyHelper::generateLazyGhost`**, which Doctrine ORM still expects when **`enable_native_lazy_objects`** is **`false`**, breaking **`composer test`** on PHP **8.4+**).
- **`DotKeyTreeAnalyzer`**, **`YamlArraySorter`**, **`TranslationDefaultLocaleResolver`:** removed **`final`** so the bundle test suite can use PHPUnit test doubles. Behaviour and supported usage are unchanged; subclassing these classes is not a documented extension point.
- **`TranslationCallSiteResolver`:** internal refactor (**`pickCallSiteFromTrace()`**); public **`resolve()`** behaviour is unchanged.
- **`NowoTranslationYamlToolsExtension::load()`:** simplified **`missing_translation_log`** handling after the configuration processor runs (removed an unreachable **`=== false`** branch; valid YAML config always yields an array for this node).

### Tests

- **PHPUnit + PCOV:** **100%** line coverage on **`src/`**.
- **`TranslationYamlCommandsTest`:** **`createDeps`** creates **`translations/`** only when missing (avoids **`mkdir(): File exists`** when a test already created that directory).
- **`MissingTranslationLogWebUiTest`:** **`setUp`** calls **`ensureKernelShutdown()`** before **`createClient()`** so a kernel leaked from another test does not trigger **“Booting the kernel before calling createClient() is not supported”**.
- Additional unit tests for missing-log commands, Web UI controller (direct calls with a minimal container and SQLite-backed repository), Doctrine metadata listener, buffer DTOs and Messenger handler, Twig globals extension, YAML command integrity-failure paths, extension **`rawConfig`** edge cases, recorder **`call_site`** updates, and **`TranslationCallSiteResolver`** synthetic stack traces.

### Documentation

- **[CONFIGURATION](CONFIGURATION.md):** missing-log **`persistBuffer`** and **0.3.3+** column resolution via metadata.
- **[UPGRADING](UPGRADING.md):** **0.3.2 → 0.3.3** (no configuration migration).
- **[README](README.md):** coverage note for **0.3.3+**.

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
