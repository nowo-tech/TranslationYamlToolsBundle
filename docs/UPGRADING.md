# Upgrading

## 0.x

This line is **0.1.x**. Treat minor and patch releases as potentially containing small command output or behaviour tweaks until a stable **`1.0.0`** is tagged. Always read `docs/CHANGELOG.md` before upgrading.

## To 0.1.0 (first tagged release)

There is no earlier semver tag. If you tracked **`dev-main`** or a commit hash:

- Prefer requiring **`^0.1`** (or an exact **`0.1.x`** tag) in Composer once published on Packagist.
- Default machine translator remains **`google`**; set **`machine_translator: deepl`** or **`libretranslate`** only if you intend to use those backends (see `docs/CONFIGURATION.md`).
- **`libretranslate_base_url`** and **`libretranslate_api_key`** are optional and default to the public LibreTranslate origin and an empty key; no change is required for existing Google-only setups.

## Machine translator routing (after 0.1.0)

- The autowired **`MachineTranslatorInterface`** is implemented by **`RoutingMachineTranslator`**, which picks **google**, **deepl**, or **libretranslate** per request. The default is still **`machine_translator`** (`google` unless changed).
- Add **`machine_translator_by_locale`** only if some locales should use a different backend than the default (see `docs/CONFIGURATION.md`). All three backend services stay registered; configure env keys for any backend you might route to.
- Custom **`MachineTranslatorInterface`** aliases in your app still replace the router entirely.
