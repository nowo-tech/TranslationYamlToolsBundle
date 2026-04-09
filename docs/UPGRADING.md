# Upgrading

## 0.x

This line is **0.1.x**. Treat minor and patch releases as potentially containing small command output or behaviour tweaks until a stable **`1.0.0`** is tagged. Always read `docs/CHANGELOG.md` before upgrading.

## To 0.1.0 (first tagged release)

There is no earlier semver tag. If you tracked **`dev-main`** or a commit hash:

- Prefer requiring **`^0.1`** (or an exact **`0.1.x`** tag) in Composer once published on Packagist.
- Default machine translator remains **`google`**; set **`machine_translator: deepl`** or **`libretranslate`** only if you intend to use those backends (see `docs/CONFIGURATION.md`).
- **`libretranslate_base_url`** and **`libretranslate_api_key`** are optional and default to the public LibreTranslate origin and an empty key; no change is required for existing Google-only setups.
