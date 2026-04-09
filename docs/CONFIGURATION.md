# Configuration

## Reference (`nowo_translation_yaml_tools`)

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `default_locale` | `string\|null` | `null` | When set, used as the default **source** locale for `fill-missing`. When `null`, the bundle uses Symfony parameters `translator.default_locale`, then `kernel.default_locale`, then `en`. |
| `yaml_tree_indent` | `int` | `4` | Spaces per indentation level when writing nested YAML (`tree`, `sort`, `fill-missing`). Allowed range: 2–12. |
| `machine_translator` | `string` | `google` | Backend for `fill-missing`: `google` (Cloud Translation v2), `deepl` (DeepL API), or `libretranslate` (LibreTranslate; free public instances are rate-limited). |
| `deepl_endpoint` | `string` | `https://api.deepl.com/v2/translate` | DeepL translate URL. Use `https://api-free.deepl.com/v2/translate` if your key is on the **Free** plan. |
| `libretranslate_base_url` | `string` | `https://libretranslate.com` | Origin of the LibreTranslate server (no `/translate` path). Prefer **self-hosting** for reliability; the public demo enforces strict limits. |
| `libretranslate_api_key` | `string` | `''` | Optional API key when your LibreTranslate instance requires one; leave empty for open public endpoints. |

Example:

```yaml
nowo_translation_yaml_tools:
    default_locale: null
    yaml_tree_indent: 4
    machine_translator: google
    deepl_endpoint: 'https://api.deepl.com/v2/translate'
    libretranslate_base_url: 'https://libretranslate.com'
    libretranslate_api_key: ''
```

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

- Env var: **`GOOGLE_TRANSLATE_API_KEY`** (required when `machine_translator` is `google`).
- API: [Translation API v2](https://cloud.google.com/translate/docs/reference/rest/v2/translate).
- Do **not** commit real keys; use `.env.local` or your secret manager.

## DeepL

- Env var: **`DEEPL_AUTH_KEY`** (required when `machine_translator` is `deepl`).
- Set `deepl_endpoint` to the **Free** API host if your key is not valid on the Pro endpoint (see table above).
- API: [Translate text](https://developers.deepl.com/docs/api-reference/translate).

## LibreTranslate

- No Google/DeepL key is required when using `machine_translator: libretranslate` with the default public URL and an empty `libretranslate_api_key`.
- Public servers apply **strict rate limits** and may return errors when busy; for repeated or bulk `fill-missing` runs, **host your own** [LibreTranslate](https://github.com/LibreTranslate/LibreTranslate) and point `libretranslate_base_url` at it.
- Set `libretranslate_api_key` if your instance is configured to require a key.
- API: [LibreTranslate API](https://github.com/LibreTranslate/LibreTranslate/blob/main/API.md).

## Replacing the machine translator

Implement `Nowo\TranslationYamlToolsBundle\MachineTranslation\MachineTranslatorInterface` and either set `machine_translator` to a built-in backend or register your service in application DI and **alias** `MachineTranslatorInterface` to it (that alias overrides the bundle default).
