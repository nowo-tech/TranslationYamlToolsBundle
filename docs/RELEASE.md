# Release process

1. Ensure `main` is green (CI) and `make release-check` passes locally.
2. Update `docs/CHANGELOG.md` with a dated section for the new version.
3. Commit and tag with an annotated tag `vX.Y.Z` including release notes in the tag message.
4. Push the tag; GitHub Actions `release.yml` creates the GitHub Release.
5. Confirm Packagist (or your proxy) picks up the new tag.

See also `docs/SECURITY.md` for the pre-release security checklist.
