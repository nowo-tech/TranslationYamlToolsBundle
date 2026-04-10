# Upgrading

## 0.x

This line is **0.3.x** (after **0.2.0**). Treat minor and patch releases as potentially containing small command output or behaviour tweaks until a stable **`1.0.0`** is tagged. Always read `docs/CHANGELOG.md` before upgrading.

## To 0.3.0 (from 0.2.x)

- In Composer, require **`^0.3`** (or pin **`0.3.x`**) to receive this line; **`^0.2`** does not pull **0.3.0**.
- Optional **`missing_translation_log`**: requires **`doctrine/orm`** and **`doctrine/doctrine-bundle`** in the app. Table name is **`{table_prefix}missing_log`** (default prefix **`nowo_translation_`**). The bundle prepends Doctrine mapping when **`enabled: true`**. See **`docs/CONFIGURATION.md`** (“Missing translation log”).
- Optional **`missing_translation_log.web_ui`**: requires **`symfony/twig-bundle`**, importing the bundle routes, and **`framework.csrf_protection`** + **`symfony/security-csrf`** for the **Mark added** form. The Flex recipe ships a **dev-only** route import.
- **`missing_translation_log.record_call_site`** (default **true**) fills column **`call_site`** with **`absolutePath:lineNumber`** of the calling code (not the YAML translation file path). Set **`false`** if you want to avoid `debug_backtrace` cost.
- **`missing_translation_log.async_persist`** and **`async_persist_strategy`** (`messenger` \| `event_dispatcher`): optional deferred flush. **`messenger`** needs **`symfony/messenger`** and **`messenger.default_bus`** (route **`MissingTranslationBufferMessage`** to an async transport for workers). **`event_dispatcher`** uses **`MissingTranslationBufferEvent`** on the app **`event_dispatcher`**; call **`stopPropagation()`** in your listener if you enqueue and persist elsewhere so the builtin Doctrine listener is skipped.
- **Symfony 8 apps**: the container may not define **`translator.default_locale`**; the bundle’s **`TranslationDefaultLocaleResolver`** already falls back to **`kernel.default_locale`**. If you read that parameter yourself, use the same pattern (see **`TranslationDefaultLocaleResolver`**).
- The bundle declares a direct Composer dependency on **`symfony/translation`** (already pulled by FrameworkBundle in typical apps).

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
