# Release process

1. Ensure the default branch is green (CI) and `make release-check` passes locally.
2. Update `docs/CHANGELOG.md`: move items from **`[Unreleased]`** into a new dated section **`[X.Y.Z] - YYYY-MM-DD`** (header must match the semver **without** the `v` prefix so `.github/workflows/release.yml` can embed it in the GitHub Release body).
3. Update `docs/UPGRADING.md` when users must change configuration or code.
4. Commit the documentation (and any code) changes, then create an **annotated** tag `vX.Y.Z` with a short release summary in the tag message.
5. Push the commit and tag; GitHub Actions **`release.yml`** creates the GitHub Release (it merges the tag message with the matching changelog section when present).
6. Confirm Packagist (or your proxy) picks up the new tag.

**Tag message:** keep the first line short (e.g. `0.3.2: missing translation log duplicate-safe persist`); GitHub Actions can merge it with the matching **`[0.3.2]`** section from `docs/CHANGELOG.md` when publishing the release.

See also `docs/SECURITY.md` for the pre-release security checklist.
