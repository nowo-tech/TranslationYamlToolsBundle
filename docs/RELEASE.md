# Release process

1. Ensure the default branch is green (CI) and `make release-check` passes locally.
2. Update `docs/CHANGELOG.md`: move items from **`[Unreleased]`** into a new dated section **`[X.Y.Z] - YYYY-MM-DD`** (header must match the semver **without** the `v` prefix so `.github/workflows/release.yml` can embed it in the GitHub Release body).
3. Update `docs/UPGRADING.md` when users must change configuration or code.
4. Commit the documentation (and any code) changes, then create an **annotated** tag `vX.Y.Z` with a short release summary in the tag message.
5. Push the commit and tag; GitHub Actions **`release.yml`** creates the GitHub Release (it merges the tag message with the matching changelog section when present).
6. Confirm Packagist (or your proxy) picks up the new tag.

**Avoid duplicate release jobs:** **`sync-releases.yml`** is intentionally **not** bound to tag pushes (only **`workflow_dispatch`** and a daily **schedule**). It backfills or updates older releases without competing with **`release.yml`** on new tags; running both on the same tag caused GitHub API races (**`already_exists`** on **`tag_name`**).

**Tag message:** keep the first line short (e.g. `1.0.0: first stable 1.x release`); GitHub Actions can merge it with the matching changelog section when publishing the release.

### Next planned tag

- Suggested tag: **`v1.0.1`** (patch) or **`v1.1.0`** (minor) as needed
- Checklist focus for the next release:
  - `docs/CHANGELOG.md`: move **`[Unreleased]`** items into a dated **`[X.Y.Z]`** section
  - `docs/UPGRADING.md`: add migration notes when users must change config or code
  - `make release-check` green before tagging

See also `docs/SECURITY.md` for the pre-release security checklist.
