# Contributing

Thank you for your interest in improving this bundle.

1. Fork the repository and create a feature branch.
2. Run quality checks locally:

   ```bash
   make install
   make qa
   make phpstan
   make test-coverage
   ```

3. Follow PSR-12 and the project PHP-CS-Fixer rules (`composer cs-check`).
4. **Documentation language:** keep user-facing docs in **English** — repository `README.md`, everything under `docs/`, and `demo/**/README.md`.
5. Add or update tests for behavioural changes. Prefer PHPUnit **`#[CoversClass(Foo::class)]`** (and **`#[CoversNothing]`** where appropriate) over **`@covers`** docblocks.
6. Update `docs/CHANGELOG.md` when the change is user-visible.

Pull requests should describe the motivation, the approach, and how you tested the change.

## Code of Conduct

This project follows the [Contributor Covenant Code of Conduct](../CODE_OF_CONDUCT.md). By participating, you are expected to uphold it. Please report unacceptable behavior to **hectorfranco@nowo.tech**.

## Git hooks (REQ-GIT-001)

Do **not** add `Co-authored-by: Cursor` or `cursoragent@cursor.com` trailers to commit messages.

```bash
make setup-hooks
make check-no-cursor-coauthor
```

`make setup-hooks` installs `.githooks/commit-msg` (or sets `core.hooksPath` to `.githooks`). Run it once per clone before your first commit.
If CI fails because trailers are already on the remote, see [GITHUB_CI.md](GITHUB_CI.md) (REQ-GIT-001) and run `make strip-cursor-coauthor-from-history` before `git push --force-with-lease`.
