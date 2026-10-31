# Feature Specification: One key in every locale (`nowo:translation-yaml:key`)

**Feature Branch**: `feat/key-command`  
**Created**: 2026-10-31  
**Status**: Implemented (Unreleased)  
**Input**: Host kit row KIT-49 (translation catalogue CLI): an application script that reads and edits one translation key in all locale catalogues without opening the files, keeping each file's format, writing all locales or none, and checking key parity. Generalised: locales from config / `framework.enabled_locales` / files found, domain argument, translator paths or `--dir`.

**Related docs**: [`docs/USAGE.md`](../../docs/USAGE.md#editing-one-key-in-every-locale), [`docs/CONFIGURATION.md`](../../docs/CONFIGURATION.md#example-key-command-locales)

---

## User Scenarios & Testing

### User Story 1 — Add or change a key everywhere (Priority: P1)

As a developer, I add a UI string to every locale in one command and the catalogues keep their layout.

**Independent Test**: `tests/Functional/Command/TranslationYamlKeyCommandTest::testAdd*`, `tests/Unit/Service/YamlCatalogueTextEditorTest`.

**Acceptance Scenarios**:

1. **Given** a JSON object with a value for every locale, **When** `add <domain> <key> <json|file|->` runs, **Then** every file gets the key, the rest of each file is byte-identical, and the key sits in alphabetical position when its siblings are sorted (else at the end of the parent block; missing parents created).
2. **Given** a missing or unknown locale in the JSON, a non-string value, an existing key without `--replace`, a group, or a key under a value, **Then** exit 1 and no file changes.
3. **Given** one locale whose edit fails (does not parse or does not read back exactly), **Then** exit 1 and **no** locale is written.
4. **Given** `--dry-run`, **Then** the changed lines per file are printed and nothing is written.

### User Story 2 — Rename and remove (Priority: P1)

1. `rename <domain> <old> <new>` moves a value or a group in every locale; it refuses when `old` is absent in a locale, `new` exists (or sits under a value) in any locale, `new` is inside `old`, or a leaf is a list.
2. `remove <domain> <key>` deletes a value or a group wherever it exists, names locales that already lacked it, removes parents left empty, and fails when no locale has it.

### User Story 3 — Read and check (Priority: P2)

1. `get <domain> <key>` prints the JSON value per locale, `(missing)` or `(group of N key(s))`.
2. `list <domain> [prefix]` prints the keys of the reference locale under the prefix with `[missing: …]` flags.
3. `missing [domain]` prints `missing` / `not in <ref>` lines per domain and locale and exits 1 on any difference (an absent locale file counts as all keys missing).

## Requirements

- **FR-001**: Locales = `--locales`, else `key_command.locales`, else `framework.enabled_locales`, else the locale files found for the domain. Reference locale = the default locale when listed, else the first.
- **FR-002**: Files = the translator paths (first `<domain>.<locale>.yaml|yml` found), or `--dir`.
- **FR-003**: Domain names match `[a-zA-Z0-9_+-]` segments separated by dots; locales `[a-zA-Z0-9_-]+`; keys are non-empty dotted paths.
- **FR-004**: Output escapes console markup from keys and values.
