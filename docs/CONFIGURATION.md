# Configuration

## Reference (`nowo_translation_yaml_tools`)

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `default_locale` | `string\|null` | `null` | When set, used as the default **source** locale for `fill-missing`. When `null`, the bundle uses Symfony parameters `translator.default_locale`, then `kernel.default_locale`, then `en`. |
| `yaml_tree_indent` | `int` | `4` | Spaces per indentation level when writing nested YAML (`tree`, `sort`, `fill-missing`). Allowed range: 2–12. |
| `machine_translator` | `string` | `google` | **Default** backend for `fill-missing` when `machine_translator_by_locale` does not match: `google`, `deepl`, or `libretranslate`. |
| `machine_translator_by_locale` | `array<string, string>` | `{}` | Override the backend **per Symfony locale** (`google`, `deepl`, or `libretranslate`). The **target** locale is checked first, then the **source** locale, then `machine_translator`. Keys match like `machine_translation_locale_map` (`pt_BR` ≡ `pt-br`). |
| `deepl_endpoint` | `string` | `https://api.deepl.com/v2/translate` | DeepL translate URL. Use `https://api-free.deepl.com/v2/translate` if your key is on the **Free** plan. |
| `libretranslate_base_url` | `string` | `https://libretranslate.com` | Origin of the LibreTranslate server (no `/translate` path). Prefer **self-hosting** for reliability; the public demo enforces strict limits. |
| `libretranslate_api_key` | `string` | `''` | Optional API key when your LibreTranslate instance requires one; leave empty for open public endpoints. |
| `machine_translation_locale_map` | `array<string, string>` | `{}` | Map **Symfony locale** identifiers to the **exact** `source` / `target` language code sent to the active backend (Google, DeepL, LibreTranslate). Keys are matched after normalizing case and `-` / `_` (e.g. `pt_BR`, `pt-br`, `PT_BR` share one entry). Values are sent **as-is**—use the format your provider expects (e.g. LibreTranslate `pt-br`, DeepL `PT-BR`, Google `pt-BR`). |

Example:

```yaml
nowo_translation_yaml_tools:
    default_locale: null
    yaml_tree_indent: 4
    machine_translator: google
    deepl_endpoint: 'https://api.deepl.com/v2/translate'
    libretranslate_base_url: 'https://libretranslate.com'
    libretranslate_api_key: ''
    # machine_translation_locale_map:
    #     pt_BR: 'pt-br'   # Symfony locale => API code (see table above)
    # machine_translator_by_locale:
    #     pt_BR: libretranslate   # use LibreTranslate when translating to/from this locale (see below)
```

## Machine translator by locale

`machine_translator_by_locale` chooses **which service** (Google vs DeepL vs LibreTranslate) runs for a given Symfony locale. Resolution order for each `translate` call: **target** locale → **source** locale → `machine_translator`.

All three backends are always registered; ensure the env vars / keys for backends you might route to are set (e.g. `DEEPL_AUTH_KEY` if any locale uses `deepl`).

## Machine translation locale map

Use `machine_translation_locale_map` when Symfony’s locale naming does not match what your provider expects. The built-in translators apply their default normalization only for locales **not** listed in the map.

If two YAML keys normalize to the same canonical key (e.g. `pt_BR` and `pt-br`), the **last** processed entry wins.

## HTTP client

`fill-missing` uses Symfony’s `HttpClientInterface`. Enable the Framework HTTP client in your app (as in the demos):

```yaml
framework:
    http_client: true
```

## Symfony translation paths

The bundle discovers YAML files under:

1. **`translator.default_path`** (FrameworkBundle parameter) when defined.
2. Otherwise **`%kernel.project_dir%/translations`**.
3. Plus **`framework.translator.default_path`** and **`framework.translator.paths`** entries parsed from `config/packages/**/translation.yaml` (or `.yml`).

Absolute paths in YAML are resolved with the container parameter bag (e.g. `%kernel.project_dir%`).

## Google Cloud Translation

- Env var: **`GOOGLE_TRANSLATE_API_KEY`** (required when `machine_translator` or `machine_translator_by_locale` routes to `google`).
- API: [Translation API v2](https://cloud.google.com/translate/docs/reference/rest/v2/translate).
- Do **not** commit real keys; use `.env.local` or your secret manager.

## DeepL

- Env var: **`DEEPL_AUTH_KEY`** (required when `machine_translator` or `machine_translator_by_locale` routes to `deepl`).
- Set `deepl_endpoint` to the **Free** API host if your key is not valid on the Pro endpoint (see table above).
- API: [Translate text](https://developers.deepl.com/docs/api-reference/translate).

## LibreTranslate

- No Google/DeepL key is required when the active backend is LibreTranslate only (`machine_translator: libretranslate` or `machine_translator_by_locale` mapping to `libretranslate`) with the default public URL and an empty `libretranslate_api_key`.
- Public servers apply **strict rate limits** and may return errors when busy; for repeated or bulk `fill-missing` runs, **host your own** [LibreTranslate](https://github.com/LibreTranslate/LibreTranslate) and point `libretranslate_base_url` at it.
- Set `libretranslate_api_key` if your instance is configured to require a key.
- API: [LibreTranslate API](https://github.com/LibreTranslate/LibreTranslate/blob/main/API.md).

## Replacing the machine translator

By default, `MachineTranslatorInterface` points to **`RoutingMachineTranslator`**, which delegates to the three built-in services. Register your own implementation in application DI and **alias** `MachineTranslatorInterface` to it to bypass routing and bundle backends entirely (that alias overrides the bundle default). `machine_translator`, `machine_translator_by_locale`, and `machine_translation_locale_map` then no longer apply unless your code uses them explicitly.
