# Security

## Scope

This bundle provides **console commands** that scan configured translation directories, **read and write YAML files**, and optionally call **Google Cloud Translation** over HTTPS. It does not expose HTTP endpoints by itself.

## Attack surface

- **Filesystem**: commands resolve paths under the project’s configured translation directories and write YAML when not using `--dry-run`.
- **Configuration**: bundle options (`default_locale`, `yaml_tree_indent`, `machine_translator`) and Symfony parameters (`translator.default_path`, `framework.translator.paths`).
- **Outbound HTTP**: `fill-missing` sends source strings to Google’s API when a key is missing in the target locale file.
- **Environment**: `GOOGLE_TRANSLATE_API_KEY` must remain secret.

## Threat model (examples)

| Threat | Mitigation |
|--------|------------|
| **Path traversal** via crafted domain/locale names | Domain/locale options are validated against discovered file names; new files are created only under resolved translation directories with a safe `domain.locale.ext` pattern. |
| **SSRF / data exfiltration** via translator | Only Google’s fixed HTTPS endpoint is used by the default implementation; request bodies contain text snippets from translation files. |
| **Secret leakage** | API keys are read from environment / DI parameters, never logged by the bundle. Do not enable verbose HTTP dumps in production for the same process. |
| **Malicious YAML** | Files are parsed with Symfony Yaml; invalid syntax aborts with an error. Extremely large files may impact memory — run commands in a controlled dev environment. |

## Secrets and cryptography

- **No symmetric crypto** in the bundle.
- Store `GOOGLE_TRANSLATE_API_KEY` in `.env.local`, your secret manager, or CI secrets — not in Git.

## Logging

- Commands use Symfony Console output only; they do **not** log translation values or API keys.

## Dependencies

- Run `composer audit` regularly and keep `symfony/*` and `php` ranges aligned with supported versions.

## Permissions and exposure

- Install as **`require-dev`** where possible so production containers do not register the bundle.
- Restrict who can run `bin/console` in shared environments.

## Release security checklist (12.4.1)

| Item | Confirm before release |
|------|-------------------------|
| `docs/SECURITY.md` up to date | [ ] |
| `.env` / secrets not committed | [ ] |
| No hard-coded API keys | [ ] |
| Safe defaults in Flex recipe / docs | [ ] |
| Input validation for CLI options | [ ] |
| `composer audit` reviewed | [ ] |
| No sensitive data in logs | [ ] |
| Outbound calls documented (Google API) | [ ] |
| File write scope documented (translations dirs) | [ ] |
| Dependency updates for known CVEs | [ ] |
| Permissions / dev-only install guidance | [ ] |
| Rate limits / quotas understood for translation API | [ ] |
