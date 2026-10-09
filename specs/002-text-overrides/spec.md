# Feature Specification: Operator text overrides

**Feature Branch**: `feat/text-overrides-2026-10`  
**Created**: 2026-10-09  
**Status**: Draft (implemented, unreleased)  
**Input**: Port the operator text overrides of an application (`src/Shared/Text/**`) into the bundle, generalized: editable domains/prefixes by config, locales from config / `framework.enabled_locales`, injectable HTML sanitizer, no dependency on the host `User` / AuditKit, configurable admin layout and roles, CSRF, no inline JS.

**Related docs**: [`docs/CONFIGURATION.md`](../../docs/CONFIGURATION.md#text-overrides-database), [`docs/USAGE.md`](../../docs/USAGE.md#text-overrides-operator-edited-texts), [`docs/UPGRADING.md`](../../docs/UPGRADING.md)

---

## User Scenarios & Testing

### User Story 1 — Overrides reach every rendering path (Priority: P1)

As a site operator, I replace a shipped message for one locale and the new text appears wherever the message is translated (Twig, forms, mails, PHP).

**Independent Test**: `tests/Unit/TextOverride/OverridingTranslatorTest`, `tests/Functional/TextOverride/TranslationOverrideWebUiTest::testOverrideRoundTripThroughTheDesk`.

**Acceptance Scenarios**:

1. **Given** an override for *(es, messages, site.hello)*, **When** `trans('site.hello', [...], null, 'es')` runs, **Then** the override is returned, formatted with the parameters (plural / ICU like catalogue messages).
2. **Given** the same override, **When** another locale or domain is requested, **Then** the catalogue text is returned.
3. **Given** the decorator, **When** `getCatalogue()` / `getCatalogues()` / `setLocale()` / `warmUp()` / `getFallbackLocales()` are called, **Then** they are delegated unchanged (catalogue keeps the shipped text).
4. **Given** the missing-translation log is enabled, **When** an overridden key is translated, **Then** it is served by the outer decorator (priority 5 < 20) and never recorded as missing.

### User Story 2 — Desk: list, edit, reset (Priority: P1)

As an operator with an allowed role, I browse editable texts by group or search, edit one message with one tab per locale, and restore the shipped text.

**Acceptance Scenarios**:

1. **Given** `overrides.editable`, **When** I open the list, **Then** only keys of configured domains starting with a configured prefix are listed; non-`messages` keys show as `Domain:key`.
2. **Given** the edit form, **When** a locale keeps the shipped text or is blank, **Then** no row is stored (an existing one is deleted).
3. **Given** a text longer than `max_length`, **When** submitted, **Then** HTTP 422 with the field error and that locale's tab selected; nothing is stored.
4. **Given** a reset POST without the confirmation checkbox, or with an invalid CSRF token, **When** submitted, **Then** nothing changes and an error flash is shown.
5. **Given** an unknown, non-editable or not-shipped key (including unconfigured `Domain:` refs), **When** requested, **Then** 404.

### User Story 3 — Safe markup (Priority: P1)

As a maintainer, I can print overridden messages with `|raw` without XSS risk.

**Acceptance Scenarios**:

1. **Given** a value containing `<`, **When** saved, **Then** it is sanitized (`TranslationOverrideHtmlSanitizerInterface`).
2. **Given** rows written outside the bundle (SQL, restore), **When** the map is loaded into the cache, **Then** values containing `<` are sanitized again.
3. **Given** no `symfony/html-sanitizer`, **When** the default sanitizer runs, **Then** every tag is stripped and any remaining tag opener is escaped.

### User Story 4 — Host integration (Priority: P2)

As an integrator, I reuse my admin layout, roles, CSP and forms.

**Acceptance Scenarios**:

1. `overrides.web_ui.layout_template` must define `title` and `body` blocks.
2. `access_roles` — any granted role passes; empty denies; without SecurityBundle the container build fails unless `allow_unauthenticated` (dev only). CSRF and Twig are required at compile time (`TextOverrideWebUiPass`).
3. The only inline `<style>` (CSS tabs) carries `nonce` from request attribute `csp_nonce`; there is no inline JavaScript and no inline `style` attribute.
4. `TranslationOverrideEditor` binds host form fields (`currentTexts()`, `save()`, `reset()`, `flush()`).

### Edge Cases

- Table missing / cache down: map is empty (warning logged), shipped texts shown; cache delete failure is logged.
- FrankenPHP worker: per-request memo cleared via `ResetInterface` (`kernel.reset`); repository resolves (and reopens) the entity manager per call.
- Default locale is always the first locale even when absent from `overrides.locales`.
- Message keys containing `:` in the `messages` domain stay addressable (prefix before `:` is only a domain when configured).

---

## Requirements

- **FR-001**: Feature disabled by default (`overrides.enabled: false`); no services, decorator or Doctrine mapping when disabled.
- **FR-002**: Doctrine mapping `NowoTranslationYamlToolsTextOverride` prepended only when enabled; table `{table_prefix}override` with unique *(locale, domain, message_key)*; the host generates the migration.
- **FR-003**: Override map cached in `cache_pool` for `cache_ttl` seconds; every write invalidates it.
- **FR-004**: Editable set = `overrides.editable` (domain ⇒ prefixes, `''` = whole domain) ∩ keys of the default-locale catalogue.
- **FR-005**: Locales = `overrides.locales` ‖ `kernel.enabled_locales` ‖ default locale; default first.
- **FR-006**: Every POST validates a CSRF token scoped to the action and key.
- **FR-007**: `updated_by` stores the user identifier string (no relation to the host user entity).
- **FR-008**: UI strings translated (en, es) in domain `NowoTranslationYamlToolsBundle`; flashes are translated before being stored.

## Code inventory

| Area | Files |
|------|-------|
| Entity / persistence | `src/TextOverride/Entity/TranslationOverride.php`, `Repository/TranslationOverrideRepository.php`, `Doctrine/TranslationOverrideMetadataListener.php` |
| Runtime | `src/TextOverride/TranslationOverrides.php`, `TranslationOverrideProviderInterface.php`, `OverridingTranslator.php` |
| Config helpers | `src/TextOverride/EditableTranslationKeys.php`, `TranslationOverrideLocales.php`, `TranslationOverrideEditor.php` |
| Sanitizing | `src/TextOverride/Html/*` |
| Web UI | `src/TextOverride/Controller/TranslationOverrideController.php`, `src/Resources/views/text_override/*`, `src/Resources/config/routes/translation_override_ui.yaml`, `src/Resources/translations/NowoTranslationYamlToolsBundle.{en,es}.yaml` |
| DI | `Configuration::overridesNode()`, `NowoTranslationYamlToolsExtension::loadOverrides()` / `rawConfigEnablesOverrides()`, `Compiler/TextOverrideWebUiPass.php`, `TwigPathsPass`, `services_overrides*.yaml` |
| Tests | `tests/Unit/TextOverride/*`, `tests/Unit/DependencyInjection/Overrides*Test.php`, `tests/Unit/DependencyInjection/Compiler/TextOverrideWebUiPassTest.php`, `tests/Functional/TextOverride/*`, `tests/Kernel/TextOverrideTestKernel.php`, `tests/Fixtures/app_text_override/` |
