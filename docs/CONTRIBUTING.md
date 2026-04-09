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
