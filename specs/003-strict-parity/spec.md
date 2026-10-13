# Feature Specification: Strict translation parity

**Feature Branch**: `main`  
**Created**: 2026-10-13  
**Status**: Implemented (1.9.0)  
**Input**: Host spec `137-kit-small-extractions` FR-007 (KIT-TRANSLATION-PARITY): `audit --strict` fails on keys missing **or extra** in any locale vs the source locale and on empty values; a `Testing\TranslationParityAssertions` PHPUnit trait wraps the same check so host suites keep only their allowlists (ports an application's `TranslationKeyParityTest` / `TranslationCompletenessTest`).

**Related docs**: [`docs/USAGE.md`](../../docs/USAGE.md#strict-parity-in-ci-and-phpunit), [`docs/UPGRADING.md`](../../docs/UPGRADING.md#from-180-to-190)

---

## User Scenarios & Testing

### User Story 1 — `audit --strict` in CI (Priority: P1)

As a maintainer, I run `nowo:translation-yaml:audit --strict` in CI and the job fails when a locale drifts from the source locale.

**Independent Test**: `tests/Functional/Command/TranslationYamlAuditCommandTest::testStrict*`, `tests/Unit/Service/TranslationYamlAuditorTest::testStrictOk*`.

**Acceptance Scenarios**:

1. **Given** a locale missing a source key, **When** `--strict` runs, **Then** exit 1 and the key is listed (`missing: <key>`).
2. **Given** a locale with a key absent from the source, **When** `--strict` runs, **Then** exit 1 and `extra: <key>` is listed.
3. **Given** a blank, `null` or empty-map value in any locale (source included), **When** `--strict` runs, **Then** exit 1 and `empty: <key>` is listed.
4. **Given** more than 20 keys of one kind, **When** listed, **Then** the first 20 are shown followed by `… and N more`.
5. **Given** the same files **without** `--strict`, **When** the audit runs, **Then** output and exit code are unchanged from 1.8.0 (extra / empty keys are ignored).

### User Story 2 — PHPUnit trait (Priority: P1)

As a host app, I replace my hand-written parity tests by one trait call and keep only my allowlists.

**Independent Test**: `tests/Unit/Testing/TranslationParityAssertionsTest`, `tests/Unit/Service/TranslationParityCheckerTest`.

**Acceptance Scenarios**:

1. `assertTranslationKeyParity($dir, $sourceLocale, $domains, $locales, $allowedDifferences, $checkEmpty, $message)` fails listing `"<locale> <domain>: missing|extra|empty key \"<key>\""` lines; a domain without a (non-empty) source file reports `source_missing`; invalid YAML reports `yaml_error`.
2. With `$locales` given, a locale without a file for a domain reports every source key missing (expected locales); with `null`, locales come from the directory and absent files are skipped.
3. `assertNoUntranslatedValues($dir, $sourceLocale, $domains, $locales, $allowedSameAsSource, $multiWordOnly, $message)` fails when a target value equals the source value; by default only multi-word source values not starting with `%` or `{` are checked.
4. Allowlists are `locale|'*' => list<'domain:key'>` with `fnmatch()` patterns.
5. `phpunit/phpunit` is only suggested: the trait lives in `src/Testing/` (excluded from the service resource) and is only loaded by test suites.

### Edge Cases

- Lists (non-associative arrays) are compared as single leaf values (same flattening as the rest of the bundle).
- Domain discovery uses `domain.locale.yaml|yml` (so `messages+intl-icu` is its own domain).

## Requirements

- **FR-001**: `TranslationYamlAuditor` rows add `missing_keys`, `extra_keys`, `empty_keys`; reports add `strict_ok` (`all_ok` unchanged).
- **FR-002**: `audit --strict` uses `strict_ok`; non-strict output unchanged.
- **FR-003**: `Service\TranslationParityChecker` (pure, no container) implements the checks, used by the auditor and the trait.
- **FR-004**: `Testing\TranslationParityAssertions` exposes `assertTranslationKeyParity()` and `assertNoUntranslatedValues()`.
- **FR-005**: 100 % coverage, PHPStan level 8, CS, Rector kept.
