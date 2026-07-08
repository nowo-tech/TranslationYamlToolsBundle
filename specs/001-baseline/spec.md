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

### Missing translation log

- **FR-MLOG-001**: `MissingTranslationRecorderInterface` / `DoctrineMissingTranslationRecorder` persist buffered misses.
- **FR-MLOG-002**: `RecordingTranslatorDecorator` wraps translator to capture misses.
- **FR-MLOG-003**: `MissingTranslationRecordContext`, `MissingTranslationLogCallSiteBuilder`, `TranslationCallSiteResolver` capture optional caller metadata.
- **FR-MLOG-004**: Async buffer — `MissingTranslationBufferMessage`, `MissingTranslationBufferEvent`, handler/listener for deferred flush.
- **FR-MLOG-005**: `MissingTranslationLog` entity + `MissingTranslationLogStatus` enum; `MissingTranslationLogMetadataListener` for table prefix.
- **FR-MLOG-006**: `MissingTranslationLogRepository` query/update API.
- **FR-MLOG-007**: `MissingTranslationLogUiController` + routes YAML for Web UI CRUD/filter actions.
- **FR-MLOG-008**: Twig views (`index`, `_table`, `_status_filters`, layout variants including dashboard/breadcrumb integration).
- **FR-MLOG-009**: `MissingTranslationLogExtension` Twig helpers; `MissingLogUiAccessSubscriber` enforces `required_role`.

---

## Success Criteria

- **SC-001**: 56/56 production files mapped in [`code-inventory.md`](code-inventory.md).
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
