# Upgrading

## 0.x

This line is **0.2.x** (after **0.1.0**). Treat minor and patch releases as potentially containing small command output or behaviour tweaks until a stable **`1.0.0`** is tagged. Always read `docs/CHANGELOG.md` before upgrading.

## To 0.2.0 (from 0.1.x)

- In Composer, require **`^0.2`** (or pin **`0.2.x`**) to receive this line; **`^0.1`** does not pull **0.2.0**.
- New console command **`nowo:translation-yaml:audit`** (read-only; optional in your workflow). See `docs/USAGE.md`.
- **`machine_translator_by_locale`** (optional): route **google** / **deepl** / **libretranslate** per Symfony locale. If you omit it, behaviour matches **0.1.0** (single `machine_translator` default).
- **`machine_translation_locale_map`** (optional): map Symfony locale identifiers to exact API language codes for the active backend. Omit it if default normalization is enough.
- The autowired **`MachineTranslatorInterface`** is implemented by **`RoutingMachineTranslator`**, which delegates to the three built-in backends. All three services remain registered; set env keys for any backend you might route to (e.g. `DEEPL_AUTH_KEY` if any locale uses `deepl`).
- Custom **`MachineTranslatorInterface`** aliases in your app still replace the router entirely.

## To 0.1.0 (first tagged release)

There is no earlier semver tag. If you tracked **`dev-main`** or a commit hash:

- Prefer requiring **`^0.1`** (or an exact **`0.1.x`** tag) in Composer once published on Packagist.
- Default machine translator remains **`google`**; set **`machine_translator: deepl`** or **`libretranslate`** only if you intend to use those backends (see `docs/CONFIGURATION.md`).
- **`libretranslate_base_url`** and **`libretranslate_api_key`** are optional and default to the public LibreTranslate origin and an empty key; no change is required for existing Google-only setups.
