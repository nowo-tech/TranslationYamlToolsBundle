# Feature Specification: TranslationYamlToolsBundle baseline (100% code coverage)

**Feature Branch**: `001-baseline`  
**Created**: 2026-07-07  
**Status**: Active  
**Input**: Backfill GitHub Spec Kit baseline documenting 100% of production code in `src/`.

**Related docs**: [`docs/SPEC-DRIVEN-DEVELOPMENT.md`](../../docs/SPEC-DRIVEN-DEVELOPMENT.md), [`docs/CONFIGURATION.md`](../../docs/CONFIGURATION.md), [`docs/USAGE.md`](../../docs/USAGE.md)  
**Code inventory (traceability)**: [`code-inventory.md`](code-inventory.md)

---

## User Scenarios & Testing

### User Story 1 — YAML tree operations (Priority: P1)

As a translator, I run console commands to flatten, nest, sort, and audit YAML translation files discovered from Symfony config.

**Independent Test**: `nowo:translation-yaml:tree` on flat dot-keys → nested tree or conflict report; `flatten` inverts; `sort` alphabetizes keys.

**Acceptance Scenarios**:

1. **Given** framework translation paths, **When** any command runs, **Then** `FrameworkTranslationPathsResolver` collects `default_path` and configured `paths`.
2. **Given** conflicting dot-keys, **When** `tree` validates, **Then** `DotKeyTreeAnalyzer` reports blocking prefix; optional `--fix-leaf-prefix` renames leaves per `yaml_tree_leaf_prefix_suffix`.

---

### User Story 2 — Fill missing via machine translation (Priority: P1)

As a translator, I bootstrap a target locale by machine-translating missing keys from the default locale.

**Acceptance Scenarios**:

1. **Given** `machine_translator=google|deepl|libretranslate`, **When** `fill-missing` runs, **Then** `RoutingMachineTranslator` delegates to configured backend with locale map overrides.
2. **Given** `machine_translator_by_locale`, **When** target locale matches, **Then** per-locale backend overrides global default.

---

### User Story 3 — Missing translation log (Priority: P2)

As a maintainer, I record runtime missing translation keys in Doctrine and review them via CLI or optional Web UI.

**Acceptance Scenarios**:

1. **Given** `missing_translation_log.enabled=true`, **When** translator misses a key, **Then** `RecordingTranslatorDecorator` buffers id/domain/locale (+ optional call site and request context).
2. **Given** `async_persist=true`, **When** kernel terminates, **Then** buffer flushes via Messenger or EventDispatcher strategy without blocking the request.
3. **Given** `web_ui.enabled=true`, **When** admin visits configured prefix, **Then** UI lists rows and supports mark-as-added workflow protected by `required_role`.
4. **Given** FrankenPHP worker mode without `kernel.reset`, **When** a request records missing keys and terminate persistence fails, **Then** the error is logged, the buffer is empty for the next request, and the worker loop continues.
5. **Given** the same EntityManager across requests, **When** DBAL upserts or clears update rows behind the identity map, **Then** Web UI reads show fresh hit counts / deletions (`HINT_REFRESH` + detach after clear).

---

### Edge Cases

- Default locale resolution: `TranslationDefaultLocaleResolver` falls back kernel/translator defaults.
- Web UI disabled: routes and Twig pass not loaded; CLI log commands still work when log enabled.
- Duplicate missing hits: recorder increments hit count and updates call site when resolved.
- Audit command: compact OK line when domain passes all checks.

---

## Requirements

### Bundle & DI

- **FR-BUNDLE-001**: `NowoTranslationYamlToolsBundle` registers extension and conditional compiler passes.
- **FR-CFG-001**: `Configuration` MUST define YAML tooling keys (`default_locale`, `yaml_tree_indent`, `yaml_tree_leaf_prefix_suffix`, machine translator settings, locale maps) and `missing_translation_log` subtree (table prefix, async persist, web UI).
- **FR-CFG-002**: Extension loads base services and conditional imports (`services_missing_translation*.yaml`).
- **FR-DI-001**: `services.yaml` wires catalog, auditor, file handler, sorter, resolvers, commands.
- **FR-DI-002**: Conditional YAML MUST register missing-log recorder, messenger handler, web controller, and routes only when features enabled.
- **FR-TWIG-001**: `TwigPathsPass` prepends app override path then adds bundle views when Web UI enabled.

### YAML services

- **FR-YAML-001**: `FrameworkTranslationPathsResolver` discovers translation directories from framework config.
- **FR-YAML-002**: `TranslationDefaultLocaleResolver` resolves source locale for comparisons.
- **FR-YAML-003**: `TranslationYamlCatalog` loads domain/locale file sets.
- **FR-YAML-004**: `TranslationYamlFileHandler` reads/writes YAML with configured indent.
- **FR-YAML-005**: `DotKeyTreeAnalyzer` validates and transforms flat↔nested key structures.
- **FR-YAML-006**: `YamlArraySorter` recursively sorts associative keys.
- **FR-YAML-007**: `TranslationYamlAuditor` produces audit reports (tree-safe, sorted, missing keys).

### Console commands

- **FR-CLI-001**: `AbstractTranslationYamlCommand` shared options (domain, locale, paths).
- **FR-CLI-002**: `nowo:translation-yaml:tree`, `flatten`, `sort`, `fill-missing`, `audit` commands.
- **FR-CLI-003**: Missing-log commands — `list`, `mark-added`, `validate`.

### Machine translation

- **FR-MT-001**: `MachineTranslatorInterface` contract for translate batch API.
- **FR-MT-002**: `GoogleTranslateMachineTranslator`, `DeeplMachineTranslator`, `LibreTranslateMachineTranslator` backends.
- **FR-MT-003**: `MachineTranslationLocaleMapper` maps Symfony locales to provider codes.
- **FR-MT-004**: `RoutingMachineTranslator` selects backend from config and per-locale overrides.
- **FR-MT-005**: `ThrottledMachineTranslator` optionally paces machine-translation HTTP calls (rate-limit friendly).
- **FR-MT-006**: `LibreTranslateBaseUrlGuard` restricts LibreTranslate base URLs to an allowlist of hosts (SSRF mitigation).

### Missing translation log

- **FR-MLOG-001**: `MissingTranslationRecorderInterface` / `DoctrineMissingTranslationRecorder` persist buffered misses (see also **FR-MLOG-010**).
- **FR-MLOG-002**: `RecordingTranslatorDecorator` wraps translator to capture misses.
- **FR-MLOG-003**: `MissingTranslationRecordContext`, `MissingTranslationLogCallSiteBuilder`, `TranslationCallSiteResolver` capture optional caller metadata.
- **FR-MLOG-004**: Async buffer — `MissingTranslationBufferMessage`, `MissingTranslationBufferEvent`, handler/listener for deferred flush.
- **FR-MLOG-005**: `MissingTranslationLog` entity + `MissingTranslationLogStatus` enum; `MissingTranslationLogMetadataListener` for table prefix.
- **FR-MLOG-006**: `MissingTranslationLogRepository` query/update API; resolves EntityManager per call, recovers closed managers, refreshes managed rows on read, detaches after DBAL deletes (FrankenPHP worker / no `kernel.reset`).
- **FR-MLOG-007**: `MissingTranslationLogUiController` + routes YAML for Web UI CRUD/filter actions.
- **FR-MLOG-008**: Twig views (`index`, `_table`, `_status_filters`, layout variants including dashboard/breadcrumb integration).
- **FR-MLOG-009**: `MissingTranslationLogExtension` Twig helpers; `MissingLogUiAccessSubscriber` enforces `required_role`.
- **FR-MLOG-010**: `DoctrineMissingTranslationRecorder` implements `ResetInterface`; `flushBuffer` on `kernel.terminate` at priority `-1024` empties the buffer before persist and never rethrows (logs via optional `LoggerInterface`).
- **FR-WORKER-001**: HTTP runtime of the missing-log feature MUST be safe under FrankenPHP worker mode when the kernel is not reset between requests (scenario B in `docs/FRANKENPHP-WORKER-AUDIT.md`).
- **FR-SEC-001**: Missing-log Web UI access goes through `MissingLogUiAccessCheckerInterface` (default `ConfigurableMissingLogUiAccessChecker`, `web_ui.security.*`, REQ-UI-002); `MissingLogWebUiSecurityPass` fails the build when the Web UI is enabled without SecurityBundle.

### Operator text overrides (feature [`002-text-overrides`](../002-text-overrides/spec.md))

- **FR-OVR-001**: Disabled by default (`overrides.enabled: false`); when enabled, `services_overrides.yaml` registers the runtime and `TranslationOverrideMetadataListener` maps `TranslationOverride` to `{table_prefix}override` (unique locale/domain/key).
- **FR-OVR-002**: `OverridingTranslator` decorates `translator` and serves overrides from `TranslationOverrideProviderInterface` / `TranslationOverrides` (cached map, invalidated on every write) before the catalogues.
- **FR-OVR-003**: `EditableTranslationKeys` (domain ⇒ prefixes ∩ default-locale keys), `TranslationOverrideLocales` (configured ‖ enabled ‖ default locale) and `TranslationOverrideEditor` (per-locale edit; form bridge for hosts) define what operators may edit.
- **FR-OVR-004**: Override markup is cleaned by `TranslationOverrideHtmlSanitizerInterface`: `SymfonyTranslationOverrideHtmlSanitizer` (inline allowlist) when symfony/html-sanitizer is installed, else `StripTagsTranslationOverrideHtmlSanitizer`.
- **FR-OVR-005**: Web UI desk — `TranslationOverrideController` (list/search, edit, reset; CSRF per action and key), routes `translation_override_ui.yaml`, `services_overrides_web.yaml`, views `text_override/*`; `TextOverrideWebUiPass` fails the build when the Web UI is enabled without its dependencies.
- **FR-OVR-006**: `TranslationOverrideRepository` resolves the EntityManager per call and recovers a closed one (worker safe, FR-WORKER-001).
- **FR-I18N-001**: Bundle UI strings ship in domain `NowoTranslationYamlToolsBundle` (`en`, `es`).

### Strict parity (feature [`003-strict-parity`](../003-strict-parity/spec.md))

- **FR-PARITY-001**: `TranslationParityChecker` (pure, no container) computes missing / extra / empty keys; `TranslationYamlAuditor` exposes them and `audit --strict` fails on `strict_ok = false`.
- **FR-PARITY-002**: `TranslationParityAssertions` (PHPUnit trait) exposes `assertTranslationKeyParity()` and `assertNoUntranslatedValues()` for host test suites.

---

## Success Criteria

- **SC-001**: 85/85 production files mapped in [`code-inventory.md`](code-inventory.md).
- **SC-002**: Config keys documented in `CONFIGURATION.md` match `Configuration.php`.
- **SC-003**: PHPUnit passes for tree analyzer, sorter, and recorder.
- **SC-004**: Demo Symfony 8 enables missing log + Web UI without manual wiring errors.

---

## Explicit non-goals

- Runtime automatic machine translation of user-facing pages (CLI `fill-missing` only unless host enables decorator separately).
- Lexik/DB translation storage migration.
- Guaranteed MT quality or glossary support.

---

## Validation

| Check | Command |
| --- | --- |
| Full QA | `composer qa` |
| Tree command | `nowo:translation-yaml:tree --domain=messages` |
| Inventory | `find src -type f \| wc -l` → 56 |
