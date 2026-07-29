# Security

## Scope

This bundle provides:

1. **Console commands** that scan configured translation directories, **read and write YAML files**, and optionally call **machine translation APIs** over HTTPS.
2. An **optional HTTP Web UI** for the missing-translation log (`missing_translation_log.web_ui`) — disabled by default.

## Attack surface

- **Filesystem**: commands resolve paths under the project’s configured translation directories and write YAML when not using `--dry-run`.
- **HTTP (optional Web UI)**: list / mark-added / clear routes under a configurable path prefix when `web_ui.enabled` is `true`.
- **Configuration**: bundle options and Symfony parameters (`translator.default_path`, `framework.translator.paths`).
- **Outbound HTTP**: `fill-missing` sends source strings to Google / DeepL / LibreTranslate when configured.
- **Environment**: `GOOGLE_TRANSLATE_API_KEY`, `DEEPL_AUTH_KEY`, and similar must remain secret.

## Threat model (examples)

| Threat | Mitigation |
|--------|------------|
| **Unauthenticated Web UI** | Default `web_ui.enabled: false`. Enabling the UI **requires** `security.authorization_checker` (SecurityBundle) unless `web_ui.security.allow_unauthenticated: true` (dev/demo only — never copy to production). Default `security.access_roles: [ROLE_ADMIN]` via `MissingLogUiAccessCheckerInterface` / `MissingLogUiAccessSubscriber` (REQ-UI-002). Also protect with firewall / `access_control` on `path_prefix`. |
| **Path traversal** via crafted domain/locale names | Domain/locale options are validated against discovered file names; new files are created only under resolved translation directories with a safe `domain.locale.ext` pattern. |
| **SSRF / data exfiltration** via translator | Backends use fixed HTTPS endpoints (or configured LibreTranslate base URL); request bodies contain text snippets from translation files. |
| **Secret leakage** | API keys are read from environment / DI parameters, never logged by the bundle. Do not enable verbose HTTP dumps in production for the same process. |
| **YAML bomb / DoS** | `TranslationYamlFileHandler` rejects files above **2 MiB**, trees deeper than **64**, or more than **50 000** nodes. Framework config YAML discovery skips oversized files. |
| **Path traversal (fill-missing)** | Domain/locale constrained to `[a-zA-Z0-9_-]+`; new YAML paths must resolve under configured translation directories. |
| **SSRF via LibreTranslate URL** | `libretranslate_base_url` host must be in `libretranslate_allowed_hosts` (default `libretranslate.com`); HTTPS-only unless `libretranslate_allow_http`. |
| **MT quota / hang DoS** | Optional `machine_translation_min_interval_ms` pacing, `machine_translation_max_requests_per_run` cap, and `machine_translation_http_timeout` (default 30s) on all backends. |
| **CSRF on UI mutations** | POST actions require CSRF tokens (`missing_log_mark_added`, clear, clear-status). |

## Web UI checklist

When enabling `missing_translation_log.web_ui.enabled`:

1. Install **`symfony/security-bundle`** (and Twig + CSRF as documented in [CONFIGURATION.md](CONFIGURATION.md)).
2. Keep **`security.allow_unauthenticated: false`** (default).
3. Keep **`security.access_roles: [ROLE_ADMIN]`** (or stricter roles / a custom **`security.access_checker`**).
4. Add **`access_control`** for your `path_prefix`.
5. Prefer installing the bundle as **`require-dev`** so production kernels do not register it.

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
| **AI security audit (REQ-SEC-004)** | Pass (conditional) recorded in monorepo `BUNDLES_SECURITY_ANALYSIS.md` |
