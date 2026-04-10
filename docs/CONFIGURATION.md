# Configuration

## Reference (`nowo_translation_yaml_tools`)

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `default_locale` | `string\|null` | `null` | When set, used as the default **source** locale for `fill-missing`. When `null`, the bundle uses Symfony parameter **`translator.default_locale`** if defined, then **`kernel.default_locale`**, then **`en`**. On some Symfony versions (e.g. **8.x**), only **`kernel.default_locale`** may be available. |
| `yaml_tree_indent` | `int` | `4` | Spaces per indentation level when writing nested YAML (`tree`, `sort`, `fill-missing`). Allowed range: 2–12. |
| `machine_translator` | `string` | `google` | **Default** backend for `fill-missing` when `machine_translator_by_locale` does not match: `google`, `deepl`, or `libretranslate`. |
| `machine_translator_by_locale` | `array<string, string>` | `{}` | Override the backend **per Symfony locale** (`google`, `deepl`, or `libretranslate`). The **target** locale is checked first, then the **source** locale, then `machine_translator`. Keys match like `machine_translation_locale_map` (`pt_BR` ≡ `pt-br`). |
| `deepl_endpoint` | `string` | `https://api.deepl.com/v2/translate` | DeepL translate URL. Use `https://api-free.deepl.com/v2/translate` if your key is on the **Free** plan. |
| `libretranslate_base_url` | `string` | `https://libretranslate.com` | Origin of the LibreTranslate server (no `/translate` path). Prefer **self-hosting** for reliability; the public demo enforces strict limits. |
| `libretranslate_api_key` | `string` | `''` | Optional API key when your LibreTranslate instance requires one; leave empty for open public endpoints. |
| `machine_translation_locale_map` | `array<string, string>` | `{}` | Map **Symfony locale** identifiers to the **exact** `source` / `target` language code sent to the active backend (Google, DeepL, LibreTranslate). Keys are matched after normalizing case and `-` / `_` (e.g. `pt_BR`, `pt-br`, `PT_BR` share one entry). Values are sent **as-is**—use the format your provider expects (e.g. LibreTranslate `pt-br`, DeepL `PT-BR`, Google `pt-BR`). |
| `missing_translation_log` | `array` | see below | Runtime missing-key log (Doctrine). Use `missing_translation_log: false` to disable the subtree explicitly. |
| `missing_translation_log.enabled` | `bool` | `false` | When **`true`**, decorates **`translator`**, records missing keys per request, flushes on **`kernel.terminate`**. In the usual **`index.php`** flow, **`terminate`** runs **after** **`$response->send()`**, so the client has already received the response body; the process may still run DB or Messenger work during terminate. Requires **`doctrine/orm`** and **`doctrine/doctrine-bundle`**; the bundle **prepends** ORM mapping for **`MissingTranslationLog`**. |
| `missing_translation_log.table_prefix` | `string` | `nowo_translation_` | Physical table name = **`{table_prefix}missing_log`** (only `[a-z0-9_]`, max 40 chars). Default → **`nowo_translation_missing_log`**. |
| `missing_translation_log.record_call_site` | `bool` | `true` | When **`true`**, each flush stores **`call_site`** (absolute **`file:line`** of the first plausible caller from `debug_backtrace`, excluding Symfony translation/Twig bridge internals). New hits can refresh **`call_site`**. Set **`false`** to avoid backtrace overhead on hot **`trans()`** paths. |
| `missing_translation_log.async_persist` | `bool` | `false` | When **`true`**, flush uses **`async_persist_strategy`** instead of calling **`persistBuffer`** directly in the recorder. If the chosen strategy is unavailable (no bus, no **`event_dispatcher`**), the recorder **falls back** to synchronous **`persistBuffer`**. |
| `missing_translation_log.async_persist_strategy` | `string` | `messenger` | Only when **`async_persist`** is **`true`**: **`messenger`** dispatches **`MissingTranslationBufferMessage`** (needs **`symfony/messenger`** + **`messenger.default_bus`**; route the message to an async transport for workers). **`event_dispatcher`** dispatches **`MissingTranslationBufferEvent`** on the app **`event_dispatcher`**; the bundle registers **`MissingTranslationBufferDoctrinePersistListener`** at priority **-1024** to call **`persistBuffer`** unless a listener with higher priority called **`stopPropagation()`** (use that to enqueue the buffer and persist later without Messenger). |
| `missing_translation_log.web_ui.enabled` | `bool` | `false` | When **`true`** (and the log is enabled), registers **`MissingTranslationLogUiController`** and runs **`TwigPathsPass`**: if **`templates/bundles/NowoTranslationYamlToolsBundle/`** exists, that path is prepended on the native Twig loader with namespace **`NowoTranslationYamlToolsBundle`**, then the bundle **`src/Resources/views`** path is appended with the same namespace so overrides win without **`twig.paths`** (see [USAGE](USAGE.md#overriding-templates-req-twig-001)). Requires **`symfony/twig-bundle`**. |
| `missing_translation_log.web_ui.path_prefix` | `string` | `/_translation_yaml_tools/missing-log` | URL prefix for the routes you import (must start with **`/`**). Use a **trailing slash** on the list URL if your router enforces strict trailing slashes. |
| `missing_translation_log.web_ui.layout_template` | `string` | `@NowoTranslationYamlToolsBundle/missing_translation_log/layout.html.twig` | Twig layout extended by **`missing_translation_log/base.html.twig`**. Exposed as Twig global **`nowo_translation_yaml_tools_missing_log_layout_template`**. Use **`.../layout_integrate_dashboard_menu.html.twig`** or **`.../layout_integrate_breadcrumb_kit.html.twig`** to align with those dashboards. |

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
    # missing_translation_log:
    #     enabled: true
    #     table_prefix: nowo_translation_
    #     web_ui:
    #         enabled: true
    #         path_prefix: '/_translation_yaml_tools/missing-log'
    #         # layout_template: '@NowoTranslationYamlToolsBundle/missing_translation_log/layout_integrate_dashboard_menu.html.twig'
```

## Missing translation log (database)

1. Install **`doctrine/orm`** and **`doctrine/doctrine-bundle`** in your application (the bundle lists them under `suggest`).
2. Enable the feature and optional **table prefix** / **web UI**:

```yaml
nowo_translation_yaml_tools:
    missing_translation_log:
        enabled: true
        table_prefix: nowo_translation_   # table: nowo_translation_missing_log
        web_ui:
            enabled: true
            path_prefix: '/_translation_yaml_tools/missing-log'
```

3. Create the table (migrations / `doctrine:schema:update` in dev only). Entity: **`Nowo\TranslationYamlToolsBundle\Entity\MissingTranslationLog`**; table name follows **`{table_prefix}missing_log`**.

4. **Import HTTP routes** (not automatic) with the same prefix as **`web_ui.path_prefix`**:

```yaml
# config/routes/nowo_translation_yaml_tools_ui.yaml
nowo_translation_yaml_tools_missing_log_ui:
    resource: '@NowoTranslationYamlToolsBundle/Resources/config/routes/missing_translation_log_ui.yaml'
    type: yaml
    prefix: '%nowo_translation_yaml_tools.missing_translation_log.web_ui.path_prefix%'
```

The Flex recipe adds this under **`config/routes/dev/`** when you install the bundle (dev only).

5. **Framework** (for the Twig UI and CSRF on “Mark added”):

```yaml
framework:
    csrf_protection: true
```

Also install **`symfony/security-csrf`** (and enable **`session`** if you do not already) so the **`csrf_token()`** Twig function is available.

6. **Optional deferred flush** (the response is usually already sent before **`kernel.terminate`**; this further decouples *how* you persist):
   - **`async_persist_strategy: messenger`** (default when **`async_persist: true`**): install **`symfony/messenger`**, route **`MissingTranslationBufferMessage`** to an async transport; the bundle registers **`PersistMissingTranslationBufferMessageHandler`** when Messenger is available.
   - **`async_persist_strategy: event_dispatcher`**: no Messenger required. Set **`async_persist: true`** and **`async_persist_strategy: event_dispatcher`**. Subscribe to **`MissingTranslationBufferEvent`**; to **only** enqueue and persist in your own worker, call **`$event->stopPropagation()`** after enqueueing so the builtin Doctrine listener (priority **-1024**) does not run.

7. **Protect** the URL in production (`access_control`, firewall, VPN, etc.).

8. **Routes** (fixed names): **`nowo_translation_yaml_tools_missing_log_index`** (GET list + `?status=pending|added|validated`), **`nowo_translation_yaml_tools_missing_log_mark_added`** (POST, CSRF id **`missing_log_mark_added`**).

9. **Console** (registered only when the log feature is enabled):

- **`nowo:translation-yaml:missing-log-list`** — `--status=pending|added|validated` (default `pending`), `--limit=…`
- **`nowo:translation-yaml:missing-log-mark-added <id>`** — mark a row as **added** (string present in YAML)
- **`nowo:translation-yaml:missing-log-validate <id>`** — mark as **validated**; optional **`--note=`**

Row fields include **`message_id`** (max 500 chars), **`domain`**, **`locale`**, **`status`**, **`hit_count`**, **`call_site`** (nullable, max 1024 chars), **`first_seen_at`**, **`last_seen_at`**, **`status_changed_at`**, **`notes`**.

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
