## AI contribution guidelines (Nowo Symfony bundle)

Use this when suggesting code, tests, documentation, or CI changes for this repository.

### Scope

- This is a **Symfony bundle** published as `nowo-tech/translation-yaml-tools-bundle` on Packagist.
- Respect the **PHP** and **Symfony** version ranges declared in `composer.json`.
- Prefer **PHP 8 attributes** for configuration and metadata. Do not introduce `doctrine/annotations` for new code.

### Code

- Follow **PSR-12** and project conventions in `.php-cs-fixer.dist.php`.
- Use **strict comparison** (`===`) where appropriate; avoid loose `==`.
- Keep changes **minimal** and consistent with existing patterns in `src/` and `tests/`.
- Before finishing: align with `composer cs-check`, `composer phpstan`, and `composer test` expectations.

### Bundle-specific notes

- Console commands may **rewrite translation YAML on disk**; keep behaviour predictable and well tested.
- **Machine translation** must stay behind `MachineTranslatorInterface`; do not hard-code vendor-specific logic outside the dedicated classes.
- Never log or echo **API keys** or full request payloads containing secrets.

### Documentation

- User-facing documentation is **English** under `docs/` as required by Nowo bundle standards.
- README sections and badges follow the canonical structure; do not add extra markdown files at repo root beyond `README.md`.

### Tests

- Add or update tests under `tests/` for new behaviour; preserve high coverage targets from `README.md` and CI.
