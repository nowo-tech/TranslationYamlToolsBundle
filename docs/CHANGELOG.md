# Changelog

All notable changes to this project are documented in this file.

## Table of contents

- [[Unreleased]](#unreleased)
- [[1.18.3] - 2026-11-12](#1183---2026-11-12)
- [[1.18.2] - 2026-11-12](#1182---2026-11-12)
- [[1.18.1] - 2026-11-12](#1181---2026-11-12)
- [[1.18.0] - 2026-11-12](#1180---2026-11-12)
- [[1.17.1] - 2026-11-12](#1171---2026-11-12)
- [[1.17.0] - 2026-11-11](#1170---2026-11-11)
- [[1.16.0] - 2026-10-31](#1160---2026-10-31)
- [[1.15.0] - 2026-10-30](#1150---2026-10-30)
- [[1.14.1] - 2026-10-30](#1141---2026-10-30)
- [[1.14.0] - 2026-10-30](#1140---2026-10-30)
- [[1.13.0] - 2026-10-29](#1130---2026-10-29)
- [[1.12.0] - 2026-10-28](#1120---2026-10-28)
- [[1.11.0] - 2026-10-24](#1110---2026-10-24)
- [[1.10.1] - 2026-10-18](#1101---2026-10-18)
- [[1.10.0] - 2026-10-15](#1100---2026-10-15)
- [[1.9.0] - 2026-10-13](#190---2026-10-13)
- [[1.8.0] - 2026-10-13](#180---2026-10-13)
- [[1.7.0] - 2026-10-13](#170---2026-10-13)
- [[1.6.1] - 2026-10-13](#161---2026-10-13)
- [[1.6.0] - 2026-10-09](#160---2026-10-09)
- [[1.5.1] - 2026-10-09](#151---2026-10-09)
- [[1.5.0] - 2026-09-28](#150---2026-09-28)
- [[1.4.6] - 2026-09-27](#146---2026-09-27)
- [[1.4.5] - 2026-09-25](#145---2026-09-25)
- [[1.4.4] - 2026-08-24](#144---2026-08-24)
- [[1.4.3] - 2026-08-19](#143---2026-08-19)
- [[1.4.2] - 2026-08-18](#142---2026-08-18)
- [[1.4.1] - 2026-08-07](#141---2026-08-07)
- [[1.4.0] - 2026-08-04](#140---2026-08-04)
- [[1.3.0] - 2026-07-30](#130---2026-07-30)
- [[1.2.3] - 2026-07-29](#123---2026-07-29)
- [[1.2.2] - 2026-07-21](#122---2026-07-21)
- [[1.2.1] - 2026-07-20](#121---2026-07-20)
- [[1.2.0] - 2026-07-17](#120---2026-07-17)
- [[1.1.3] - 2026-07-16](#113---2026-07-16)
- [[1.1.2] - 2026-06-30](#112---2026-06-30)
- [[1.1.1] - 2026-07-10](#111---2026-07-10)
- [[1.1.0] - 2026-07-08](#110---2026-07-08)
- [[1.0.0] - 2026-06-30](#100---2026-06-30)
- [[0.4.1] - 2026-06-30](#041---2026-06-30)
- [[0.4.0] - 2026-06-30](#040---2026-06-30)
- [[0.3.10] - 2026-06-30](#0310---2026-06-30)
- [[0.3.9] - 2026-06-10](#039---2026-06-10)
- [[0.3.8] - 2026-05-12](#038---2026-05-12)
- [[0.3.7] - 2026-04-16](#037---2026-04-16)
- [[0.3.6] - 2026-04-16](#036---2026-04-16)
- [[0.3.5] - 2026-04-15](#035---2026-04-15)
- [[0.3.4] - 2026-04-16](#034---2026-04-16)
- [[0.3.3] - 2026-04-15](#033---2026-04-15)
- [[0.3.2] - 2026-04-14](#032---2026-04-14)
- [[0.3.1] - 2026-04-10](#031---2026-04-10)
- [[0.3.0] - 2026-04-10](#030---2026-04-10)
- [[0.2.0] - 2026-04-09](#020---2026-04-09)
- [[0.1.0] - 2026-04-09](#010---2026-04-09)

## [Unreleased]

### Fixed

- **Unit boundary tracker: per-worker message depth, request stack (audit NX CR1–CR3).** The tracker kept one
  process-wide "message in flight" flag and judged a main request outermost without looking at other main requests.
  A Messenger worker run in-process inside a message handler ended the outer message, so the inner worker's messages
  and a main request handled later by the outer handler cleared the outer unit (CR1); a main request handled
  in-process inside another main request cleared the outer one (CR2); a message skipped by a
  `WorkerMessageReceivedEvent` listener (`shouldHandle(false)`) stayed in flight until the worker stopped (CR3). It now
  keeps a stack of the running workers (`WorkerStartedEvent` / `WorkerRunningEvent` / `WorkerStoppedEvent`), each with
  its own message, and a stack of the main requests in flight, popped by their own `kernel.finish_request` /
  `kernel.terminate` (unbalanced events heal); a skipped message ends with its received event or at its worker's next
  `WorkerRunningEvent`. Message boundaries clear only outside another message. No configuration change.
- **Unit boundary tracker: aborted worker runs, skip read, nested terminate (audit OB).** A Messenger run aborted by an
  exception thrown out of `Worker::run()` (a throwing received, handled or running listener) left its worker "in
  flight", so inside a long-running command every later worker's messages stopped clearing (OB-M1) and the worker stack
  grew with every aborted run (OB-L1); the tracker now holds weak references to the worker and to the received event of
  the message in flight, and drops a run as soon as either is gone. The `shouldHandle(false)` read at the end of a
  received event no longer ends an outer message when a listener already ended the skipped one (OB-L2). The
  `kernel.terminate` of a main request handled in-process inside another one no longer clears the outer request nor
  resets the tracker (OB-L3). Note: in hosts with Messenger, DoctrineBundle's own subscriber clears every manager after
  each message, so a worker run inside a handler keeps the bundle's pending events and memos, not the identity map.

## [1.18.3] - 2026-11-12

### Fixed

- **`PrivateEntityManagerFlushBridge::postFlush()` could throw out of the host's `postFlush`** (audit NX follow-up, Low). `hasPendingWork()` and `pendingCount()` ran unguarded after the host's commit; a throwing unit of work escaped. They are now guarded: the exception class is logged and the bridge flush is skipped.
- **The stale check of `TranslationYamlToolsEntityManager::live()` could throw.** On any `Throwable` the manager is now treated as stale and replaced (as `reset()` drops it), logging only the exception class.

## [1.18.2] - 2026-11-12

Target version: **1.18.2** (patch, no configuration change). See [UPGRADING](UPGRADING.md#to-1182).

### Fixed

- **A broken private entity manager could make every request fail until the worker restarted (audit NX,
  REQ-RUNTIME-002).** `TranslationYamlToolsEntityManager::reset()` counted the unflushed bundle work (which computes
  the unit of work's change sets) BEFORE dropping the private manager. When that computation threw (a wrongly typed
  association, a throwing listener), the broken manager was kept and the boundary subscriber, which runs at
  `PHP_INT_MAX` on every main request, threw again on every request. The manager is now dropped first; the
  pending-work inspection and the `clear()` run afterwards, each in its own `try`/`catch` that logs a generic warning
  (exception class only, never its message or row values). The dedicated-manager path of
  `EntityManagerBoundarySubscriber` is guarded the same way: a failed inspection never stops the `clear()`, and a
  failed `clear()` recreates the manager by name instead of throwing.

## [1.18.1] - 2026-11-12

### Fixed

- **Console boundary counter could leak in a long-lived process (audit NM / NL2-v, REQ-RUNTIME-002).** The two
  boundary subscribers (`EntityManagerBoundarySubscriber`, which clears the bundle's private / dedicated entity
  manager, and `TranslationOverridesClearSubscriber`, which drops the override map memo) counted running console
  commands with `++` on `console.command` (priority 4096) and `--` on `console.terminate` (priority -4096). A
  `console.terminate` listener in between that threw or stopped the propagation, or a `console.error` listener that
  threw (Symfony then skips `console.terminate`), left the count above zero forever. In a kept-alive worker that ran a
  command in-process, the entity manager boundary then never cleared the bundle's manager again between HTTP requests
  (managed text overrides and missing-log rows, and unflushed changes, kept across requests), and neither subscriber
  cleared at the end of the outermost command any more. In the other direction, a `console.command` listener above
  4096 that stopped the propagation skipped the `++` while the `--` still ran, so a nested command cleared the outer
  unit mid-run.
  Every unit-of-work state change now happens at `PHP_INT_MAX` (new internal `UnitOfWorkFrames`): each console run is
  a frame keyed by its command and its input (a command that runs itself in-process is one frame per run), which saves
  the message flag and the main-request depth when pushed and restores them when removed; `console.terminate`
  removes only its own frame (and the frames above it), a terminate without a frame removes nothing; `console.error`
  marks the frame, and a marked frame whose terminate never came is dropped at the next main request, received
  message, command or worker stop; the end of a main request or a message drops the frames started inside it. Main
  requests are counted, so a main request handled in-process inside another one no longer clears or ends the outer
  unit. A top-level `kernel.terminate` (nothing else running) resets the main-request count, so a request whose `kernel.finish_request` never came (a throwing `kernel.exception` listener) does not stop the next clears (audit NR-b M1). The clears keep their priorities (the end-of-command clear only for the run that started as the outermost
  unit). `WorkerMessageSkipEvent` (dispatched by `messenger:failed:retry`) ends the in-flight message.
  Affected: 1.17.1 (overrides memo) and 1.18.0 (entity manager boundary).

### Tests

- `tests/Functional/Worker/ConsoleBoundaryCounterTest.php`: a real console `Application` and a real `HttpKernel`
  sharing one event dispatcher, no services resetter, for both subscribers: a throwing `console.terminate` listener,
  a throwing `console.error` listener (also at `PHP_INT_MIN`), a self-recursive command whose inner run fails with a
  throwing error listener, an unknown nested command, a nested command whose `console.command` propagation is stopped
  above 4096, a throwing `WorkerMessageReceivedEvent` listener inside an in-process consume command, a nested main
  request, a command run by a controller that never terminates, and the unchanged outermost / main request clears.
  The subscriber unit tests drive every boundary through a real event dispatcher (`tests/Support/BoundaryDriver.php`).

## [1.18.0] - 2026-11-12

### Changed

- **The bundle never flushes the application's entity manager (REQ-ARCH-001 rule 6).** Before, with
  `doctrine.entity_manager` unset (the default), the bundle wrote its rows through the application's default manager:
  a text-override save (Web UI, `TranslationOverrides::set()` / `flush()`, `TranslationOverrideEditor`), the missing-log
  "mark added" action and the `missing-log-mark-added` / `missing-log-validate` commands flushed every change the
  application had left pending, and a failed bundle flush closed the application's manager. The bundle now writes
  through a **private entity manager** built on the connection, configuration (mapping, metadata cache, proxies),
  event manager and SQL filters of the manager that maps the bundle entities (`TranslationYamlToolsEntityManager`).
  Same tables, same connection, no migration, no configuration change. Details:
  - the private manager holds only bundle rows; a failed bundle write closes only it, and it is created again on the
    next call; the application's manager is never flushed, cleared, closed or reset;
  - the source manager's enabled SQL filters and their parameters are mirrored on every call (a filter whose
    parameters changed is re-created, so no stale parameter survives);
  - a DBAL middleware on that connection only (doctrine/dbal 4) marks the private manager stale after a rollback or a
    savepoint rollback; a stale manager is replaced before its next use unless it still has pending work;
  - it is dropped at every outermost unit-of-work boundary and at `kernel.reset` (see Fixed).
  `doctrine.private_entity_manager: false` restores the pre-1.18 behaviour (deprecated, removed in 2.0).
- Writing texts staged with `TranslationOverrideEditor::save(..., flush: false)` (or `TranslationOverrides::set(...,
  flush: false)`) through the application's `$entityManager->flush()` is deprecated: a `postFlush` bridge still writes
  them, right after the application's commit, and triggers a deprecation. Call `$editor->flush()`; wrap both flushes
  in `$connection->transactional()` to commit them together. A failure of that bridge flush is logged at error level
  and never rethrown (the application's commit is done; rethrowing from `postFlush` would leave its unit of work dirty
  and its next flush would write its rows twice). The application manager is recognised by its unit of work, so a
  decorated manager works too.
- `doctrine.entity_manager` naming the application's default manager keeps the 1.17 write path (bundle flushes commit
  its pending changes; a failed one resets it) and now triggers a build-time deprecation.
- A boundary that discards unflushed bundle changes (private or dedicated manager) logs a warning with the count.
- The rollback middleware goes on the connection of the manager whose mapping holds the bundle entities (found in the
  metadata driver chains), the default manager's only when none does.

### Fixed

- **Bundle-owned entity managers are cleared at the unit-of-work boundaries (REQ-RUNTIME-002 rule 4).** The new
  `EntityManagerBoundarySubscriber` drops the private manager, or clears the dedicated `doctrine.entity_manager` (and
  recreates it by name when a failed write closed it), at the main request start and `kernel.terminate`, Messenger
  message received / handled / failed, and the outermost console command start / terminate. It does nothing inside a
  nested command, a command run from a request or a message, or a request handled from a command or a message, and
  nothing when `doctrine.entity_manager` names the host's default manager (the host clears its own managers).
  Before, override and missing-log rows loaded by the Web UI or a command stayed managed until `kernel.reset`.

### Documentation

- USAGE and CONFIGURATION: "Standard Symfony host" and "DDD / hexagonal host" sections; the form bridge example
  uses `$editor->flush()`; FRANKENPHP-WORKER-AUDIT lists the new boundary subscriber and worker tests.

### Tests

- Equivalence of the write path with v1.17.1 (literal copies under `tests/Support/Legacy`): same reads and the same
  override and missing-log rows through the old path, the private manager, the opt-out and a dedicated manager.
- Private manager integration tests (host pending changes untouched on every write path, rollback / savepoint
  staleness, failed flush, filters, nested commands, host transaction, flush bridge), a kept-alive worker test (800
  requests, two locales, no services resetter, flat memory, host identity map never flushed), a DDD host kernel test
  (XML-mapped domain, bundle on its own manager) and `lint:container` for every mode.

## [1.17.1] - 2026-11-12

### Fixed

- **Worker state (REQ-RUNTIME-002 rule 1).** `TranslationOverrides` memoises the override map per unit of work, but
  only `kernel.reset` dropped the memo. Where the services resetter does not run between units of work (a host that
  removes, replaces or bypasses `services_resetter`, or drives `http_kernel` directly; Messenger consumers started with
  `--no-reset`; long-running console commands), a worker kept serving the map it loaded first and never saw overrides
  saved, changed or removed by another worker or process, until it saved an override itself. With the stock Symfony
  kernel, `FRANKENPHP_RESET_KERNEL=false` alone is not affected: `Kernel::handle()` still runs the resetter before every
  request. Affected: 1.6.0 to 1.17.0 with `overrides.enabled: true`. No cross-user data is involved (the map is the same
  for every user); the effect is stale text.
  The new internal `TranslationOverridesClearSubscriber` drops the memo at the start of every main request and at
  `kernel.terminate`, when a Messenger message is received, handled or failed, and when the outermost console command
  starts and terminates. A command run in-process from another command or from a message handler
  (`Application::doRun()`) does not drop the outer unit's memo (console depth counter plus an in-flight message flag,
  cleared on handled, failed and `WorkerStoppedEvent`). The subscriber receives the memo as an
  `IGNORE_ON_UNINITIALIZED_REFERENCE` reference, so a boundary never builds it. `TranslationOverrides` keeps
  `ResetInterface`.

### Tests

- `tests/Functional/Worker/WorkerBoundariesTest.php`: one kept-alive kernel with the services resetter never called;
  500 HTTP requests through `http_kernel`, 500 messages through a Messenger `Worker` without `ResetServicesListener`
  (every 7th handler fails) and 500 console runs, two tenants (English / Spanish) in alternation, another worker
  editing both overrides before every unit; 1 MiB memory margin after a 50-unit warm-up. Nested `doRun()` tests from a
  command and from a message handler, unit tests of the subscriber and a direct `reset()` test.

## [1.17.0] - 2026-11-11

New doctrine.entity_manager maps the bundle entities on a host-declared dedicated manager only; the host default entity manager stays the default; recovery resets only the bundle's manager. See [UPGRADING](https://github.com/nowo-tech/TranslationYamlToolsBundle/blob/v1.17.0/docs/UPGRADING.md#to-1170).

### Fixed

- `TranslationOverrideRepository` called `ManagerRegistry::resetManager()` **without a name** when the override manager
  was closed. That reset the host's default manager (and left the bundle's closed when it is another one). It now
  resets the manager of `TranslationOverride` by name (rule 6 of the bundle architecture bar). Standard hosts with one
  default manager see no change.

### Added

- `nowo_translation_yaml_tools.doctrine.entity_manager` (default `null`): maps the bundle entities (missing log and
  overrides) on that manager only, so a DDD host can keep them off its own manager. Also:
  - the entities are removed from every other manager, including one with `auto_mapping` (audit LD-TYT-L1);
  - the host's default manager stays the default even when the host does not set `default_entity_manager`, including
    the Flex shorthand (audit LD-TYT-M1);
  - the build fails when the host's `doctrine.orm.entity_managers` does not declare the manager, so a mistyped name
    no longer creates a new manager on the default connection (audit LD-TYT-L2).

  `null` keeps them on the default manager.

## [1.16.0] - 2026-10-31

nowo:translation-yaml:key: get/add/rename/remove/list/missing a key across every locale catalogue, format-preserving, all-or-nothing writes. See [UPGRADING](UPGRADING.md#to-1160).

### Added

- **`nowo:translation-yaml:key`** — `get` / `list` / `add` / `rename` / `remove` one key in every locale catalogue of a domain and `missing` (key parity, exit 1 for CI). Textual edits keep each file's quoting, order and comments (new keys placed alphabetically when the siblings are sorted); every result is re-parsed and compared with the original before writing, so all locales change or none does; `--dry-run` prints the changed lines; `--locales` / `--dir` per run. New service `Service\YamlCatalogueTextEditor`. See [Usage — Editing one key](USAGE.md#editing-one-key-in-every-locale).
- Config **`key_command.locales`** (default `[]` = `framework.enabled_locales`, else the locale files found).

## [1.15.0] - 2026-10-30

Page forms are FormKit types with a bundle FormKit profile; requires nowo-tech/form-kit-bundle (REQ-TWIG-005 rule 11). See [UPGRADING](UPGRADING.md#to-1150).

### Changed

- **FormKit Types (REQ-TWIG-005 rule 11):** `nowo-tech/form-kit-bundle` (^2.16) is now a required dependency. The four Web UI form types (`MissingTranslationLogActionType`, `TranslationOverrideFilterType`, `TranslationOverrideTextsType`, `TranslationOverrideResetType`) carry `#[FormKitConfig('translation_yaml_tools')]` + `FormOptionsTrait` and add their fields through FormKit; the extension prepends the framework-neutral profile `nowo_form_kit.profiles.translation_yaml_tools` (translation domain `NowoTranslationYamlToolsBundle`, no auto placeholder / help) unless the host defines it. Field names, CSRF token ids and labels are unchanged. See [UPGRADING](UPGRADING.md#unreleased).

## [1.14.1] - 2026-10-30

Bundle routes are opt-in again: controllers are no longer autoconfigured, so a host's routing.controllers import does not expose them (REQ-SF-004 rule 7). See [UPGRADING](UPGRADING.md#to-1141).

### Fixed

- **Routes are opt-in (REQ-SF-004 rule 7):** `MissingTranslationLogUiController` and `TranslationOverrideController` are no longer autoconfigured; they are tagged `controller.service_arguments` and `container.service_subscriber` explicitly. On Symfony >= 7.3 the host's default `config/routes.yaml` (`routing.controllers`) no longer mounts the missing-log and overrides Web UI routes on `/`, `/clear`, `/edit`, … without `web_ui.path_prefix`; they only exist when the host imports `@NowoTranslationYamlToolsBundle/Resources/config/routes/*.yaml`.

## [1.14.0] - 2026-10-30

TranslationYamlTools: UiKit page contract for both Web UIs, pagination, translated flashes; SEC-004 BL: literal-boolean switches, allow_unauthenticated debug-only, placeholder-preserving overrides, export-ignore See [UPGRADING](UPGRADING.md#to-1140).

Both opt-in Web UIs (missing-translation log, text-overrides desk) move to the UiKit page contract (REQ-UI-001 /
REQ-UI-003). See [UPGRADING](UPGRADING.md#unreleased).

### Security

REQ-SEC-004 re-audit 2026-10-30 (`main` `2d5e355`, Pass (good) / Low, monorepo Appendix BL); the five Lows are fixed:

- **L1:** `missing_translation_log.enabled`, `.web_ui.enabled`, `.web_ui.security.allow_unauthenticated` (and its
  deprecated top-level alias), `overrides.enabled`, `overrides.web_ui.enabled` and
  `overrides.web_ui.security.allow_unauthenticated` must be literal booleans. An `%env(bool:…)%` placeholder was
  `(bool)`-cast as a placeholder string — always `true`, whatever the env value (fail-open); it is now refused with an
  `InvalidConfigurationException`.
- **L2:** `web_ui.security.allow_unauthenticated: true` (both UIs) makes the container refuse to compile unless
  `kernel.debug` is true (`DebugOnlyRequirement`).
- **L3:** `.gitattributes` `export-ignore` keeps `demo/` (in-memory `admin` / `admin` user, MySQL root password),
  `tests/`, `.cursor/`, `.specify/`, `.github/`, `.scripts/`, `.githooks/`, `specs/`, `scripts/`, `docs/images/` and
  the tooling configs out of the dist archive.
- **L4:** overrides must keep exactly the placeholders of the shipped text (`%name%`, ICU `{name}`): the desk shows a
  field error and `TranslationOverrideEditor::save()` throws (`placeholderErrors()` / `TranslationOverridePlaceholders`
  for host forms) — parameters are substituted after sanitizing, so an added `%url%` could inject a host value into a
  `|raw` message. The default sanitizer no longer allows `span[class]` (no host CSS class for UI redress).
- **L5:** `docs/SECURITY.md`: text-override desk threat row, REQ-SEC-004 audit table, release checklist updated.

### Added

- **UiKit page contract (REQ-UI-003):** the missing-translation log is a UiKit `page/list` (status tabs, Clear current
  status / Clear all rows behind the UiKit confirm `<dialog>`, Mark added row action, pagination `?page=` /
  `?per_page=`); the text-overrides desk is a UiKit `page/list` (search / group filters, pagination) plus a `page/form`
  (UiKit locale tabs, Restore shipped text in the delete zone behind the confirm `<dialog>`). View models come from
  `Ui\MissingTranslationLogPageFactory` / `Ui\TranslationOverridePageFactory`; `Ui\WebUiPages` resolves layout,
  templates and form themes. `docs/TEMPLATES.md` lists every page → scaffold, view model and data block.
- **Template contract:** canonical keys `missing_translation_log.web_ui.templates.index`,
  `overrides.web_ui.templates.index|edit` (per-page overrides) and `overrides.web_ui.form_themes` (default
  `@NowoUiKit/form/nowo_ui_layout.html.twig`); `MissingTranslationLogRepository::countByStatus()` and an `$offset`
  argument on `findByStatus()`.
- Contract test `tests/Integration/Contract/PageContractTest.php` (UiKit `PageContractTestCase`, strict
  missing-translation rule) and `LegacyLayoutBridgeTest`; 7-locale `missing_log.*` catalogue (the missing-log UI and
  its flashes were English-only).
- Container compilation fails with a clear message when a Web UI is enabled without `NowoUiKitBundle` registered
  (`UiKitRequirement`), next to the existing fail-closed CSRF check (`FormCsrfRequirement`).
- Demo: UiKit host layout (`nowo_ui_kit.layouts.admin`, Bootstrap 5 loaded by the demo), text-overrides desk enabled
  for `demo.*` messages, Playwright e2e for both UIs and a third README screenshot (`text-overrides.webp`).

### Changed

- **Template contract:** `web_ui.layout_template` defaults to `null` (= `nowo_ui_kit.layouts.admin`) for both UIs.
  The pages extend UiKit scaffolds and fill data blocks only (`nowo_ui_list_row`, `nowo_ui_content`,
  `nowo_ui_form_body`); markup carries `nowo-ui-*` classes and UiKit theme slots, no Bootstrap classes.
- `nowo-tech/ui-kit-bundle` constraint `^1.4 || ^2.2` → `^2.17.3`.
- `TranslationOverrideResetType`: the confirmation checkbox became a hidden `confirm` marker (`1`); the UiKit confirm
  dialog asks for confirmation. `TranslationOverrideTextsType` has no `_tab` field any more (UiKit locale tabs).
  `TranslationOverrideFilterType` lost its Bootstrap `row_attr` / placeholder `attr` (UiKit `FilterField`).
- `TranslationOverrideController` takes a `TranslationOverridePageFactory` instead of the layout string;
  `MissingTranslationLogUiController` takes an optional page factory and translator (flashes translated).

### Deprecated

- `missing_translation_log.web_ui.css_framework` (set `nowo_ui_kit.css_framework`; still seeds it when unset),
  `web_ui.required_role` and the top-level `web_ui.allow_unauthenticated` (deprecation notice when set).
- 1.x layouts without the UiKit canonical blocks in `web_ui.layout_template`: wrapped by
  `missing_translation_log/_legacy_layout.html.twig` / `text_override/_legacy_layout.html.twig` (deprecation notice);
  `layout_integrate_dashboard_menu.html.twig` / `layout_integrate_breadcrumb_kit.html.twig`; the Twig globals
  `nowo_translation_yaml_tools_missing_log_layout_template` / `nowo_translation_yaml_tools_css_framework`;
  `TranslationOverrideTextsType::TAB_FIELD`. All removed in 2.0.

### Removed

- Bundle chrome: `missing_translation_log/{layout,base,_status_filters,_table}.html.twig`,
  `text_override/{layout,form_theme}.html.twig` (Bootstrap from jsDelivr CDN, inline `onsubmit="return confirm()"`,
  inline `<style>`). The bundle ships no layout, CSS, JavaScript or CDN link.

### Fixed

- Missing-translation log `call_site`: frames of the text-overrides `OverridingTranslator` are skipped, so the caller
  (controller, template) is recorded instead of `OverridingTranslator.php` when both features are enabled.

## [1.13.0] - 2026-10-29

TranslationYamlTools: HTTP deadlines on machine translation, 7 bundle locales with parity check, demo smoke in CI See [UPGRADING](UPGRADING.md#to-1130).

### Added

- Translations for **it**, **fr**, **pt**, **de** and **nl** (`NowoTranslationYamlToolsBundle.<locale>.yaml`), with the same keys as `en` (REQ-I18N-002).
- `make validate-translations` now runs in the root container: `yaml-lint` plus `.scripts/validate-translations.php` (the 7 required locales exist and have the same keys as `en`; no empty messages). A PHPUnit test runs the same check. The old demo lint moved to `make validate-translations-demos` (REQ-MAKE-004).
- `make demo-smoke` boots `demo/symfony8`, waits for a final HTTP 200 on `/` and tears the stack down. It runs as the `demo-smoke` CI job on every push / PR, nightly and on tags (`.github/workflows/demo-smoke.yml`), and in `release-check` (REQ-TEST-011).
- Demo Caddyfiles declare the timeout hierarchy: PHP `max_execution_time` / `max_input_time` 60 s, Caddy `servers.timeouts.write` 90 s, FrankenPHP `max_wait_time` 30 s (REQ-RUNTIME-001).
- Docs: the timeout hierarchy (CONFIGURATION, DEMO-FRANKENPHP), the demo smoke URL, the supported locales and how to override bundle labels from `translations/` (USAGE, REQ-I18N-001).

### Changed

- Machine-translation requests (Google, DeepL, LibreTranslate) now send `max_duration` as well as `timeout`, both set to `machine_translation_http_timeout`. Before, a backend that kept sending bytes slowly could exceed the configured timeout; now the setting is a total deadline. Tests cover the timeout failure path (REQ-RUNTIME-001).
- `rector.php` targets PHP 8.2 (`PhpVersion::PHP_82`) and `phpstan.neon.dist` sets `phpVersion: 80200`, matching the `php >=8.2` floor (REQ-CS-003 / REQ-SF-001).

### Fixed

- Demo `make up` waits for the entrypoint's own `composer install` to finish (PID 1 becomes FrankenPHP) before running `composer install` again, so a fresh checkout no longer races two installs on `vendor/`.

## [1.12.0] - 2026-10-28

Security: the admin UI refuses to compile without form CSRF protection (CsrfProtectionPass, fail closed). See [UPGRADING](UPGRADING.md#to-1120).

### Security

- CSRF fails closed for both Web UIs: new `FormCsrfRequirement` (used by `TextOverrideWebUiPass` and `MissingLogWebUiSecurityPass`) makes the container refuse to compile when `overrides.web_ui.enabled` or `missing_translation_log.web_ui.enabled` is on and `security.csrf.token_manager` or `form.type_extension.csrf` is missing, or `framework.form.csrf_protection` is false. Before, the overrides pass only checked the token manager (form CSRF could still be disabled) and the missing-log UI had no check at all, so mark added / clear log (`MissingTranslationLogActionType` declares its own CSRF options) accepted tokenless cross-site POSTs in that configuration. See [UPGRADING](UPGRADING.md#unreleased) and [SECURITY](SECURITY.md#threat-model-examples).

### Added

- `make check-no-raw-html-form` (`.scripts/check-no-raw-html-form.sh`) fails on raw `<form>` / `<input>` / `<textarea>` / `<select>` in bundle views and demo templates (the `text_override/form_theme.html.twig` form theme is the only allowlisted file) and runs in `release-check` (REQ-TWIG-005).

### Changed

- Demo: the login page renders a `LoginFormType` Symfony Form (`_username`, `_password`, `_csrf_token` / `authenticate`) with `form_start` / child loop / `form_end` instead of raw inputs (REQ-TWIG-005).

- Developer tooling: `make release-check` runs the full REQ-MAKE-002 chain with `ensure-up` first, then the Cursor co-author, open-PR and Twig `extra` gates, composer-sync, the new `cs-fix` step, cs-check, rector-dry, phpstan, igor, translations, coverage-check and the demo release-check.

## [1.11.0] - 2026-10-24

Bundle views render form bodies through the child loop (REQ-TWIG-003). See [UPGRADING](UPGRADING.md#to-1110).

### Changed

- Web UI forms are Symfony Form types rendered with `form_start` + child loop (`{% for child in form %}{% if not child.rendered %}`) + `form_end` instead of hand-written HTML (REQ-TWIG-003, REQ-TWIG-005): `Form\MissingTranslationLogActionType` (missing-log "Mark added" / "Clear current status" / "Clear all rows", CSRF + hidden `status`), `Form\TranslationOverrideFilterType` (overrides desk GET search/group filter, query parameters still `q` / `group`), `Form\TranslationOverrideTextsType` (one textarea per locale, fields still `texts[<locale>]`, plus the CSS-only locale tabs rendered by the new `text_override/form_theme.html.twig`) and `Form\TranslationOverrideResetType` (confirmation checkbox). Controllers use the form factory + `handleRequest()`; routes, CSRF token ids, flashes, redirects and 403/422 behaviour are unchanged. Request field names of the POST forms changed (the CSRF token and `status`/`confirm` now live under the form name) — see [UPGRADING](UPGRADING.md#unreleased) if you override these templates or post to these routes yourself.
- `symfony/form` (`^7.4 || ^8.0`) is now a runtime requirement; `overrides.web_ui.enabled` fails the container build with a clear message when `framework.form` is disabled.
- Dependencies refreshed (Composer and pnpm/npm lock files of the bundle and its demos, campaign 2026-10-19).
- `.php-cs-fixer.dist.php`: `new ParallelConfig(2)` (REQ-CS-001).
- Documentation: wave-1 REQ compliance — README badges (GitHub stars, Coverage) and documentation list order, CONTRIBUTING Cursor Agent settings and post-release-commit trailer check, doc TOCs, Spec Kit baseline refresh (85/85: text overrides and strict parity folded into the baseline spec and SPEC-DRIVEN-DEVELOPMENT scope).

## [1.10.1] - 2026-10-18

Allows nowo-tech/ui-kit-bundle 2.x (^1.4 || ^2.2) so hosts can move to UiKit 2.2. See [UPGRADING](UPGRADING.md#to-1101).

### Changed

- Allows nowo-tech/ui-kit-bundle 2.x (`^1.4 || ^2.2`); dev lock and demo lock use 2.2.0. No template change: with UiKit 2.x `nowo-ui.css` ships in `@layer nowo-ui`, so in the standalone missing-log layouts the unlayered Bootstrap CDN stylesheet wins equal-specificity conflicts with kit rules; hosts that want the 1.x cascade override the `stylesheets` block and link `css/nowo-ui.unlayered.css` (UiKit 2.x only).

## [1.10.0] - 2026-10-15

### Added

- **`TranslationParityAssertions::assertLiteralKeysExist()`** (and `TranslationParityChecker::checkLiteralKeys()`): fails when PHP passes a literal key to `->trans('a.b')` (default domain) or `addFlash('type', 'a.b')` that the source catalogue lacks — a key missing in every locale, which parity / completeness checks cannot detect (Nowo REQ-LOCALE-011 item 5). Reports key + file; allowlist with `domain:key` patterns; `minimumChecked` guards an empty scan.

## [1.9.0] - 2026-10-13

### Added

- **`nowo:translation-yaml:audit --strict`**: also fails (exit 1) on keys present in a locale but not in the source locale (**extra**) and on **empty** values (blank string, `null`, empty map) in any locale, and lists the offending keys (`missing:` / `extra:` / `empty:`, first 20 per kind). Without `--strict` the output and exit code are unchanged.
- **`Service\TranslationParityChecker`**: container-free checks over a translation directory — missing / extra / empty keys vs a source locale (`checkParity()`) and values copied untranslated from the source (`checkUntranslated()`), with per-locale `domain:key` allowlists (`fnmatch()` patterns, `*` = every locale).
- **`Testing\TranslationParityAssertions`** PHPUnit trait: `assertTranslationKeyParity()` and `assertNoUntranslatedValues()` for host test suites (`phpunit/phpunit` is a suggestion, not a runtime requirement). See [Usage — Strict parity](USAGE.md#strict-parity-in-ci-and-phpunit) and `specs/003-strict-parity`.
- `TranslationYamlAuditor` report rows now include `missing_keys`, `extra_keys`, `empty_keys`; reports include `strict_ok`.

## [1.8.0] - 2026-10-13

### Changed

- **Requirements:** Symfony floor raised from `^7.0` to `^7.4` (`^7.4 || ^8.0`), matching what CI actually tests; the redundant Symfony `7.0` CI matrix cell (which resolved to the latest 7.x anyway) was removed. README requirements and badge updated.

## [1.7.0] - 2026-10-13

### Fixed

- `missing_translation_log: ~` / `missing_translation_log: true` enabled the recorder services but the Doctrine ORM mapping for `MissingTranslationLog` was not prepended; the prepend check now follows the same rules as the processed configuration.
- Text overrides of keys shipped in ICU catalogues (`<domain>+intl-icu`, or an explicit `+intl-icu` domain) are now formatted with `formatIntl()` like the catalogue message, so ICU plurals/selects and `{name}` placeholders render instead of the raw pattern.
- The missing-translation buffer is now also flushed on `console.terminate` and after every Messenger worker message (handled or failed); previously keys recorded in commands were only persisted via `kernel.terminate` and keys recorded by workers were dropped when services were reset.
- The missing-translation table-prefix metadata listener is no longer bound to the `default` Doctrine connection; it now applies to every connection (entity managers on other connections get the configured table name).

## [1.6.1] - 2026-10-13

### Changed

- Development: `composer.json` pins `config.platform.php` to 8.2.0 so the committed lock stays installable on the minimum PHP; CI overrides the platform per matrix cell.
- Repository: `.twig-cs-fixer.cache` is now git-ignored.

### Dependencies

- `doctrine/orm` 3.7.4 → 3.7.5 (development lock; #47).
- `nowo-tech/ui-kit-bundle` 1.9.1 → 1.11.0 (development lock; #48).

## [1.6.0] - 2026-10-09

### Added

- **Text overrides** (opt-in `overrides`, default disabled): operator-edited per-locale replacements for catalogue messages, stored in Doctrine table `{table_prefix}override` (entity `TextOverride\Entity\TranslationOverride`, host generates the migration).
  - `OverridingTranslator` decorates `translator` (keeps `TranslatorBagInterface`, `LocaleAwareInterface`, `WarmableInterface`; formats parameters / plurals / ICU like catalogue messages; provider resolved lazily).
  - `TranslationOverrides`: map cached in `overrides.cache_pool` (default `cache.app`, TTL `overrides.cache_ttl` = 3600 s), memoised per request (`ResetInterface`), invalidated on every write; storage failures fall back to shipped texts.
  - Editable domains / key prefixes by config (`overrides.editable: { messages: ['site.'], SomeBundle: ['x.'] }`); non-`messages` keys addressed as `Domain:key`.
  - Locales from `overrides.locales`, else `framework.enabled_locales`.
  - `TranslationOverrideHtmlSanitizerInterface` (`overrides.html_sanitizer`): default inline allowlist via `symfony/html-sanitizer` when installed, else strip all tags; applied on save **and** when the map is loaded.
  - Web UI (`overrides.web_ui`): list by group + search, edit with one CSS-only tab per locale, reset with a no-JS confirmation checkbox, CSRF on every POST, `access_roles` (default `[ROLE_ADMIN]`), `layout_template` (blocks `title` / `body`), inline `<style>` carries the `csp_nonce` request attribute. Compile-time checks for SecurityBundle, CSRF and Twig (`TextOverrideWebUiPass`).
  - `TranslationOverrideEditor`: form bridge for host forms (`currentTexts()`, `save()`, `reset()`).
  - UI translations (`NowoTranslationYamlToolsBundle` domain): en, es.
- Spec `specs/002-text-overrides/spec.md`.

### Changed

- `TwigPathsPass` also registers the bundle Twig namespace when only the text-overrides Web UI is enabled.

### Dependencies

- Dev: `symfony/html-sanitizer`, `symfony/css-selector`, `symfony/cache` (explicit). `symfony/html-sanitizer` added to `suggest`.

## [1.5.1] - 2026-10-09

### Changed

- Code style: sorted the `SortDirection` import in `MissingTranslationLogRepository` (PHP CS Fixer).

### Dependencies

- Runtime lock: `nowo-tech/ui-kit-bundle` 1.8.3 -> 1.9.1 (Dependabot #41/#46 + update).
- Dev tooling: `doctrine/orm` 3.7.4 (#43), `igor-php/igor-php` ^0.10 (v0.10.1, #44), `nowo-tech/phpstan-frankenphp` v1.2.3 (#45), phpstan group (#42) -> `phpstan/phpstan` 2.3.1, `phpstan/phpstan-phpunit` 2.1.1, `phpstan/phpstan-symfony` 2.1.0; `rector/rector` 2.7.0, `phpunit/phpunit` 10.5.66.
- Demo (Symfony 8): `doctrine/orm` 3.7.4, `doctrine/dbal` 4.5.0, `nowo-tech/ui-kit-bundle` v1.9.1, `twig/twig` v3.30.0, `twig/extra-bundle` v3.29.0, `nowo-tech/hot-reload-bundle` v1.5.5, `nowo-tech/twig-inspector-bundle` v1.1.7.

[1.8.0]: https://github.com/nowo-tech/TranslationYamlToolsBundle/releases/tag/v1.8.0
[1.7.0]: https://github.com/nowo-tech/TranslationYamlToolsBundle/releases/tag/v1.7.0
[1.6.1]: https://github.com/nowo-tech/TranslationYamlToolsBundle/releases/tag/v1.6.1
[1.6.0]: https://github.com/nowo-tech/TranslationYamlToolsBundle/releases/tag/v1.6.0
[1.5.1]: https://github.com/nowo-tech/TranslationYamlToolsBundle/releases/tag/v1.5.1

## [1.5.0] - 2026-09-28

### Security

- Empty `missing_translation_log.web_ui.security.access_roles` is fail-closed (deny). Compiler pass wires `MissingLogUiAccessSubscriber` when the Web UI is enabled and `allow_unauthenticated` is false.

### Changed

- **Doctrine ORM SortDirection:** replace string `'ASC'`/`'DESC'` in `#[ORM\OrderBy]` and QueryBuilder `orderBy`/`addOrderBy` with `SortDirection::Ascending`/`Descending` (doctrine/orm deprecation, https://github.com/doctrine/orm/issues/11313); require `doctrine/orm` `^3.7` where applicable.

## [1.4.6] - 2026-09-27

### Added

- **REQ-CS-008:** `igor-php/igor-php` (require-dev only), root `igor.json`, Composer/`Makefile` `igor` target, and `release-check` wiring for FrankenPHP worker-state audit.

### Changed

- **Worker safety (Igor):** justified `// @igor-ignore` annotations and/or `ResetInterface` / request-scoped fixes so `make igor` passes on package `src/`.

[1.5.0]: https://github.com/nowo-tech/TranslationYamlToolsBundle/releases/tag/v1.5.0
[1.4.6]: https://github.com/nowo-tech/TranslationYamlToolsBundle/releases/tag/v1.4.6

## [1.4.5] - 2026-09-25

### Fixed

- **FrankenPHP worker mode (no `kernel.reset`):** `DoctrineMissingTranslationRecorder::flushBuffer()` catches and logs (`@?logger`) any `\Throwable` from synchronous persistence or async dispatch instead of letting it escape `kernel.terminate` and end the worker loop. The listener now runs at priority `-1024` so keys translated by other terminate listeners are flushed in the same request.
- **Missing-log Web UI freshness:** `MissingTranslationLogRepository` resolves the entity manager per call (replacing a closed one), resets it when `flush()` fails and closes it, reads rows with `Query::HINT_REFRESH`, and detaches managed log entities after DBAL `clearAll` / `clearByStatus` so deletions stay visible without an EntityManager reset.
- **QA:** fixed PHPStan findings (0 errors at level 8); tests no longer call `chdir()` / `error_reporting()`.

### Documentation

- **[FRANKENPHP-WORKER-AUDIT](FRANKENPHP-WORKER-AUDIT.md):** scenario B (kernel not reset) audit — verdict viable; W-01/W-02 remediations.
- **[UPGRADING](UPGRADING.md)** / **[CONFIGURATION](CONFIGURATION.md)** / **[DEMO-FRANKENPHP](DEMO-FRANKENPHP.md):** worker-mode notes for missing-log terminate flush.

[1.4.5]: https://github.com/nowo-tech/TranslationYamlToolsBundle/releases/tag/v1.4.5

## [1.4.4] - 2026-08-24

### Changed

- **Demos:** MySQL env policy in FrankenPHP stack (REQ-DEMO-011).
- **QA:** add `phpstan-frankenphp` extension (REQ-CS-005).
- **Docs:** PHP-FIG PSR evaluation (REQ-CS-007).

### Notes

- **No API or configuration changes** for integrators unless noted above.

[1.4.4]: https://github.com/nowo-tech/TranslationYamlToolsBundle/releases/tag/v1.4.4

## [1.4.3] - 2026-08-19

### Security

- **CI:** run `composer audit --locked` after dependency install (REQ-SEC / P3).

[1.4.3]: https://github.com/nowo-tech/TranslationYamlToolsBundle/releases/tag/v1.4.3

## [1.4.2] - 2026-08-18

### Changed

- **Demos:** pin `nowo-tech/hot-reload-bundle` to `^1.4` with FrankenPHP Mercure/`hot_reload` (`dev`/`test` only).

[1.4.2]: https://github.com/nowo-tech/TranslationYamlToolsBundle/releases/tag/v1.4.2

## [1.4.1] - 2026-08-07

### Fixed

- **CI:** register `NowoUiKitBundle` in missing-translation-log test fixture `bundles.php` so Twig can resolve `@NowoUiKitBundle` macros.

[1.4.1]: https://github.com/nowo-tech/TranslationYamlToolsBundle/releases/tag/v1.4.1

## [1.4.0] - 2026-08-04

### Added
- **REQ-TWIG-004:** require `twig/extra-bundle` + `twig/string-extra`; `make check-twig-extra` in `release-check`; demos register `TwigExtraBundle`.
- **Twig-CS-Fixer:** `vincentlanglet/twig-cs-fixer`, `.twig-cs-fixer.php`, `composer twig:lint` / `twig:fix`.

### Changed

- **REQ-UI-001-kit:** Requires `nowo-tech/ui-kit-bundle` `^1.4`. Missing-log layout/base load `asset('css/nowo-ui.css', 'nowo_ui_kit')` and import `@NowoUiKitBundle/macros/ui.html.twig`. Extension seeds `nowo_ui_kit` from `missing_translation_log.web_ui.css_framework` when the host has not configured UiKit. Demo registers `NowoUiKitBundle` + `nowo_ui_kit.yaml`.

[1.4.0]: https://github.com/nowo-tech/TranslationYamlToolsBundle/releases/tag/v1.4.0

## [1.3.0] - 2026-07-30

### Added

- **REQ-UI-001 `missing_translation_log.web_ui.css_framework`** — enum (`bootstrap5` default; also `bootstrap` / `bootstrap4` / `tabler` / `tailwind` / `foundation` / `custom` / `none`). Container parameter and Twig global **`nowo_translation_yaml_tools_css_framework`**. Default layout loads Bootstrap 5 CDN when the value is Bootstrap/Tabler-compatible; use `custom` (or match the host) with DashboardMenu / BreadcrumbKit / project layouts.

### Changed

- Missing-log **`base.html.twig`** stacks `stylesheets` / `javascripts` with **`{{ parent() }}`** so host / bridge layouts keep their assets (REQ-UI-001).
- Default **`layout.html.twig`**: `nowo-ui-css-*` / `data-css-framework` on `<html>`; CDN gated by `css_framework`.

### Documentation

- **[CONFIGURATION](CONFIGURATION.md)** / **[USAGE](USAGE.md):** `css_framework`, layout without forking, freeze rule for Twig overrides.
- **[UPGRADING](UPGRADING.md)** section **To 1.3.0**.

## [1.2.3] - 2026-07-29

### Security

- **REQ-UI-002:** missing-log Web UI now uses canonical **`missing_translation_log.web_ui.security`** (`access_roles` default `[ROLE_ADMIN]`, optional **`access_checker`**, **`allow_unauthenticated`**). Legacy **`required_role`** and top-level **`allow_unauthenticated`** remain as BC aliases (mapped via config normalization). New **`MissingLogUiAccessCheckerInterface`** + **`ConfigurableMissingLogUiAccessChecker`**; subscriber and controller enforce the checker instead of a single scalar role.
- **REQ-SEC-002:** release checklist includes **REQ-SEC-004** Pass (conditional) row.

### Added

- **Makefile:** `check-open-prs`, `coverage-check` / `test-coverage-100`, `demo-smoke`, `down-dev`; `release-check` wires the full REQ-MAKE-002 chain.
- **SF-005:** `SYMFONY_DEPRECATIONS_HELPER=max[direct]=0` in `phpunit.xml.dist` and CI.
- **docs/COVERAGE.md** — justified coverage exclusions; **100%** line gate.
- FrankenPHP Friendly Worker Mode banner in README (REQ-DOCS-017).
- Spec Kit inventory refresh for Web UI / Security / machine-translation files.

### Documentation

- **[CONFIGURATION](CONFIGURATION.md)** / **[SECURITY](SECURITY.md)** / **[UPGRADING](UPGRADING.md):** document `security.access_roles` / `access_checker` / `allow_unauthenticated` and BC aliases.
- GitHub About: plain description, Packagist homepage, topics (`php`, `symfony`, `symfony-bundle`, `frankenphp`, …).

## [1.2.2] - 2026-07-21

### Fixed

- **Missing translation log:** **`persistBuffer`** and **`MissingTranslationLog`** now truncate **`message_id`** (max 500), **`domain`** (max 180), and **`locale`** (max 32) before write — same pattern as **`call_site`** / request fields. Avoids **`SQLSTATE[22001]`** / MySQL **1406** (*Data too long for column 'message_id'*) when a missing key is a long free-text id. **No database migration.**

### Documentation

- **[CONFIGURATION](CONFIGURATION.md)** / **[UPGRADING](UPGRADING.md):** document column length limits and truncation on write for **1.2.2+**.

## [1.2.1] - 2026-07-20

### Fixed

- **Missing translation log:** **`persistBuffer`** now uses native **UPSERT** on MySQL/MariaDB (`INSERT … ON DUPLICATE KEY UPDATE`) and SQLite/PostgreSQL (`INSERT … ON CONFLICT … DO UPDATE`). Duplicate keys no longer raise **`UniqueConstraintViolationException`** / MySQL **1062** on the common path (so observability tools that report caught SQLSTATE **`23000`** — e.g. Sentry DBAL reporters — stay quiet). Other platforms keep an **UPDATE-then-INSERT** fallback with a race catch. **No database migration** (unique index unchanged).

### Documentation

- **[CONFIGURATION](CONFIGURATION.md)** / **[USAGE](USAGE.md)** / **[UPGRADING](UPGRADING.md):** document UPSERT behaviour for **1.2.1+**.

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
