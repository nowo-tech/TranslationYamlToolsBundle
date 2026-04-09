# Installation

## Composer

```bash
composer require --dev nowo-tech/translation-yaml-tools-bundle
```

The package is intended as a **development** dependency: it registers console commands that rewrite translation YAML on disk.

## Enable the bundle

### Symfony Flex

If you use Flex, the recipe registers `NowoTranslationYamlToolsBundle` for the `dev` environment and adds `config/packages/translation_yaml_tools.yaml` plus an empty `GOOGLE_TRANSLATE_API_KEY` entry in `.env`.

### Manual

Add the bundle in `config/bundles.php`:

```php
return [
    // ...
    Nowo\TranslationYamlToolsBundle\NowoTranslationYamlToolsBundle::class => ['dev' => true],
];
```

Ensure `framework.translator` is configured so `translator.default_path` and discovery of extra `paths` match your project. See [Configuration](CONFIGURATION.md).

## Environment

For `nowo:translation-yaml:fill-missing`, define:

```dotenv
GOOGLE_TRANSLATE_API_KEY=your_api_key
```

See [Configuration](CONFIGURATION.md) for details and security notes.

## Demos

Optional **Symfony 7 / 8** FrankenPHP demos (Twig Inspector + translation playground) are in [`demo/`](../demo/README.md). They mount this repository at `/var/translation-yaml-tools-bundle` for the Composer path repository.
