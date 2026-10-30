# Security

## Table of contents

- [Scope](#scope)
- [Attack surface](#attack-surface)
- [Threat model (examples)](#threat-model-examples)
- [Web UI checklist](#web-ui-checklist)
- [REQ-SEC-004 AI security audit](#req-sec-004-ai-security-audit)
- [Secrets and cryptography](#secrets-and-cryptography)
- [Logging](#logging)
- [Dependencies](#dependencies)
- [Permissions and exposure](#permissions-and-exposure)
- [Release security checklist (12.4.1)](#release-security-checklist-1241)

## Scope

This bundle provides:

1. **Console commands** that scan configured translation directories, **read and write YAML files**, and optionally call **machine translation APIs** over HTTPS.
2. An **optional HTTP Web UI** for the missing-translation log (`missing_translation_log.web_ui`) — disabled by default.

## Attack surface

- **Filesystem**: commands resolve paths under the project’s configured translation directories and write YAML when not using `--dry-run`.
- **HTTP (optional Web UIs)**: missing-log list / mark-added / clear routes, and the text-overrides desk (list / edit / restore), under configurable path prefixes when the respective `web_ui.enabled` is `true`.
- **Translator decorator**: text overrides stored in the database are served for every `trans()` call when `overrides.enabled` is `true`.
- **Configuration**: bundle options and Symfony parameters (`translator.default_path`, `framework.translator.paths`).
- **Outbound HTTP**: `fill-missing` sends source strings to Google / DeepL / LibreTranslate when configured.
- **Environment**: `GOOGLE_TRANSLATE_API_KEY`, `DEEPL_AUTH_KEY`, and similar must remain secret.

## Threat model (examples)

| Threat | Mitigation |
|--------|------------|
| **Unauthenticated Web UI** | Default `web_ui.enabled: false`. Enabling the UI **requires** `security.authorization_checker` (SecurityBundle) unless `web_ui.security.allow_unauthenticated: true`, which the container **refuses unless `kernel.debug` is true** (dev/demo only). The enable / `allow_unauthenticated` switches must be **literal booleans** (an `%env(bool:…)%` placeholder is refused instead of being cast to `true`). Default `security.access_roles: [ROLE_ADMIN]` via `MissingLogUiAccessCheckerInterface` / `MissingLogUiAccessSubscriber` (REQ-UI-002). Also protect with firewall / `access_control` on `path_prefix`. |
| **Text-override desk abuse** (operator edits) | Desk disabled by default; `overrides.web_ui.security.access_roles` (default `[ROLE_ADMIN]`, empty = deny); CSRF per key; only keys under `overrides.editable` prefixes that exist in the catalogue; markup sanitized on save **and** when the map is loaded (`symfony/html-sanitizer` inline allowlist without `class` attributes, else strip all tags). Parameters (`%name%`, ICU `{name}`) are substituted **after** sanitizing: an override must keep exactly the placeholders of the shipped text (refused otherwise), and hosts that print a message with `\|raw` must escape its parameters. Editors are trusted content authors (links are allowed). |
| **Dist contents** | `.gitattributes` `export-ignore` keeps the demo (local `admin` / `admin` user), tests and tooling out of the Composer dist. |
| **Path traversal** via crafted domain/locale names | Domain/locale options are validated against discovered file names; new files are created only under resolved translation directories with a safe `domain.locale.ext` pattern. |
| **SSRF / data exfiltration** via translator | Backends use fixed HTTPS endpoints (or configured LibreTranslate base URL); request bodies contain text snippets from translation files. |
| **Secret leakage** | API keys are read from environment / DI parameters, never logged by the bundle. Do not enable verbose HTTP dumps in production for the same process. |
| **YAML bomb / DoS** | `TranslationYamlFileHandler` rejects files above **2 MiB**, trees deeper than **64**, or more than **50 000** nodes. Framework config YAML discovery skips oversized files. |
| **Path traversal (fill-missing)** | Domain/locale constrained to `[a-zA-Z0-9_-]+`; new YAML paths must resolve under configured translation directories. |
| **SSRF via LibreTranslate URL** | `libretranslate_base_url` host must be in `libretranslate_allowed_hosts` (default `libretranslate.com`); HTTPS-only unless `libretranslate_allow_http`. |
| **MT quota / hang DoS** | Optional `machine_translation_min_interval_ms` pacing, `machine_translation_max_requests_per_run` cap, and `machine_translation_http_timeout` (default 30s) on all backends. |
| **CSRF on UI mutations** | POST actions require CSRF tokens (`missing_log_mark_added`, clear, clear-status; overrides desk save / delete). **Fails closed**: `TextOverrideWebUiPass` and `MissingLogWebUiSecurityPass` (via `FormCsrfRequirement`) refuse to compile the container when either Web UI is enabled without `security.csrf.token_manager` + `form.type_extension.csrf`, or with `framework.form.csrf_protection: false` — also with `allow_unauthenticated` (action forms declaring their own CSRF options would otherwise accept tokenless POSTs). |

## Web UI checklist

When enabling `missing_translation_log.web_ui.enabled`:

1. Install **`symfony/security-bundle`** (and Twig + CSRF as documented in [CONFIGURATION.md](CONFIGURATION.md)).
2. Keep **`security.allow_unauthenticated: false`** (default; `true` is refused unless `kernel.debug`), as a literal boolean.
3. Keep **`security.access_roles: [ROLE_ADMIN]`** (or stricter roles / a custom **`security.access_checker`**).
4. Add **`access_control`** for your `path_prefix`.
5. Prefer installing the bundle as **`require-dev`** so production kernels do not register it.

## REQ-SEC-004 AI security audit

| Date | Version / commit | Grade | Risk | Findings | Record |
|------|------------------|-------|------|----------|--------|
| 2026-07-29 | 1.4.x | Pass (conditional) | Medium | Canonical `access_roles` + checker, CSRF, HTTP timeouts (remediated) | Monorepo `BUNDLES_SECURITY_ANALYSIS.md` §7 |
| 2026-09-28 | 1.x | Pass (good) | Low | Empty `access_roles` fail-closed; YAML 2 MiB cap | §4 |
| 2026-10-30 | `main` `2d5e355` (1.14.0) | Pass (good) | Low | Lows L1 env-placeholder `allow_unauthenticated` fail-open, L2 `allow_unauthenticated` accepted in prod, L3 dist contents, L4 override allowlist / params substituted after sanitizing, L5 this document — **all fixed in 1.14.0**. No Critical / High / Medium | Monorepo Appendix BL |

## Secrets and cryptography

- **No symmetric crypto** in the bundle.
- Store API keys in `.env.local`, your secret manager, or CI secrets — not in Git.

## Logging

- Commands use Symfony Console output only; they do **not** log translation values or API keys.

## Dependencies

- Run `composer audit` regularly and keep `symfony/*` and `php` ranges aligned with supported versions.

## Permissions and exposure

- Install as **`require-dev`** where possible so production containers do not register the bundle.
- Restrict who can run `bin/console` in shared environments.
- Never expose demos that set `allow_unauthenticated: true` on a public host.

## Release security checklist (12.4.1)

| Item | Confirm before release |
|------|-------------------------|
| `docs/SECURITY.md` up to date | [ ] |
| `.env` / secrets not committed | [ ] |
| No hard-coded API keys | [ ] |
| Safe defaults in Flex recipe / docs | [ ] |
| Web UI requires SecurityBundle (or explicit allow_unauthenticated) | [ ] |
| Input validation for CLI options | [ ] |
| YAML size/depth/node caps | [ ] |
| `composer audit` reviewed | [ ] |
| No sensitive data in logs | [ ] |
| Outbound calls documented (MT APIs) | [ ] |
| File write scope documented (translations dirs) | [ ] |
| Dependency updates for known CVEs | [ ] |
| Permissions / dev-only install guidance | [ ] |
| Rate limits / quotas understood for translation API | [ ] |
| **CSRF** on Web UI mutations | [ ] |
| **AI security audit (REQ-SEC-004)** | Pass (good) — 2026-10-30, monorepo `BUNDLES_SECURITY_ANALYSIS.md` Appendix BL (Lows fixed in 1.14.0) |
