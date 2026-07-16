# Installation

## Composer

```bash
composer require --dev nowo-tech/translation-yaml-tools-bundle
```

The package is intended as a **development** dependency: it registers console commands that rewrite translation YAML on disk.

## Enable the bundle

### Symfony Flex

If you use Flex, the recipe registers `NowoTranslationYamlToolsBundle` for the `dev` environment and adds `config/packages/translation_yaml_tools.yaml` plus an empty `GOOGLE_TRANSLATE_API_KEY` entry in `.env` (only needed when `machine_translator` is `google`).

### Manual

Add the bundle in `config/bundles.php`:

```php
return [
    // ...
    Nowo\TranslationYamlToolsBundle\NowoTranslationYamlToolsBundle::class => ['dev' => true],
];
```

Ensure `framework.translator` is configured so `translator.default_path` and discovery of extra `paths` match your project. See [Configuration](CONFIGURATION.md).

Optional **runtime missing-key log** (Doctrine table + optional Twig UI) needs **`doctrine/orm`** and **`doctrine/doctrine-bundle`** in your app and is configured under **`missing_translation_log`** — see [Configuration](CONFIGURATION.md) (“Missing translation log”). Twig overrides use **`templates/bundles/NowoTranslationYamlToolsBundle/`** (documented in [Usage](USAGE.md#overriding-templates-req-twig-001)).

### Web UI prerequisites

If you enable **`missing_translation_log.web_ui.enabled`**, you also need:

| Package / feature | Why |
|-------------------|-----|
| **`symfony/twig-bundle`** | Renders the HTML UI |
| **`symfony/security-csrf`** + `framework.csrf_protection` | CSRF on mutating forms |
| **`symfony/security-bundle`** | Required by default (`security.authorization_checker`). Without it, the container fails unless you set **`allow_unauthenticated: true`** (dev/demo only). |

See [SECURITY.md](SECURITY.md) and the **`access_control`** snippet in [CONFIGURATION.md](CONFIGURATION.md).

## Environment

For `nowo:translation-yaml:fill-missing`, enable the HTTP client and configure the backend in `nowo_translation_yaml_tools` (see [Configuration](CONFIGURATION.md)):

- **Google** (default): set `GOOGLE_TRANSLATE_API_KEY` in `.env` / secrets.
- **DeepL**: set `DEEPL_AUTH_KEY` and `machine_translator: deepl` (and `deepl_endpoint` if you use the Free API host).
- **LibreTranslate**: set `machine_translator: libretranslate`; no paid API key is required for many public instances (rate limits apply).

```dotenv
# Only when using Google as the machine translator:
GOOGLE_TRANSLATE_API_KEY=
```

## Demos

Optional **Symfony 7 / 8** FrankenPHP demos (Twig Inspector + translation playground) are in [`demo/`](../demo/README.md). They mount this repository at `/var/translation-yaml-tools-bundle` for the Composer path repository.
