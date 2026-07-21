# Translation YAML Tools Bundle

[![CI](https://github.com/nowo-tech/TranslationYamlToolsBundle/actions/workflows/ci.yml/badge.svg)](https://github.com/nowo-tech/TranslationYamlToolsBundle/actions/workflows/ci.yml)
[![Packagist Version](https://img.shields.io/packagist/v/nowo-tech/translation-yaml-tools-bundle.svg?style=flat)](https://packagist.org/packages/nowo-tech/translation-yaml-tools-bundle)
[![Packagist Downloads](https://img.shields.io/packagist/dt/nowo-tech/translation-yaml-tools-bundle.svg)](https://packagist.org/packages/nowo-tech/translation-yaml-tools-bundle)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/PHP-8.2%2B-777BB4?logo=php)](https://php.net)
[![Symfony](https://img.shields.io/badge/Symfony-7.0%2B%20%7C%208.0%20%7C%208.1%2B-000000?logo=symfony)](https://symfony.com)

> ⭐ **Found this useful?** Install from [Packagist](https://packagist.org/packages/nowo-tech/translation-yaml-tools-bundle) and consider starring [the repository](https://github.com/nowo-tech/TranslationYamlToolsBundle).

Symfony developer tools for **YAML translation files**: discover configured translation directories, convert flat dot-keys to a **nested tree** (with structural validation), **flatten** nested maps back to dot-keys at the file root, **sort** keys alphabetically, and **fill missing** keys in a target locale using **Google Cloud Translation**, **DeepL**, or **LibreTranslate** (pluggable `MachineTranslatorInterface`). The default source locale follows Symfony’s translator / kernel default locale (see **`docs/CONFIGURATION.md`**) unless you set **`nowo_translation_yaml_tools.default_locale`**.

## Features

- Resolves translation paths from **`translator.default_path`** and from **`config/packages/**/translation.yaml`** (`framework.translator.default_path` and `paths`).
- **Interactive** console flow (arrow keys) to pick **domain**; for **`tree`**, **`flatten`**, and **`sort`**, **`--locale` is optional** (omit to process every locale for that domain). Non-interactive: **`--domain`** alone runs all locales; **`--locale`** limits to one file. Other commands (e.g. **`fill-missing`**) keep their own locale / target options.
- **`nowo:translation-yaml:tree`** — validates that dot-keys can be represented as a nested tree; on failure prints the conflicting prefix. Optional **`--fix-leaf-prefix`** renames blocking leaves (suffix configurable via **`yaml_tree_leaf_prefix_suffix`**, default **`index`**).
- **`nowo:translation-yaml:flatten`** — writes a one-level map with dot-separated keys (inverse of the tree layout).
- **`nowo:translation-yaml:sort`** — recursive alphabetical sort of associative keys.
- **`nowo:translation-yaml:fill-missing`** — merges missing keys into a target locale using the configured machine translator (Google, DeepL, or LibreTranslate); optional `--tree` output with the same validation as the tree command.
- **`nowo:translation-yaml:audit`** — read-only report: tree-safe YAML, alphabetical key order, missing keys vs source locale; compact **OK** line per domain when everything passes.
- Configurable **YAML indent** (`yaml_tree_indent`) and **leaf-prefix suffix** (`yaml_tree_leaf_prefix_suffix` for `tree --fix-leaf-prefix`) for dumps / renames.

## Requirements

- PHP `>=8.2 <8.6` (Symfony **8.x** apps need **PHP 8.4+**)
- Symfony `^7.0 || ^8.0` (FrameworkBundle, Console, HttpClient, Yaml, …)
- For **fill-missing**: enable `framework.http_client: true`, choose `machine_translator` in config, and set `GOOGLE_TRANSLATE_API_KEY` (Google) or `DEEPL_AUTH_KEY` (DeepL) when using those backends; **LibreTranslate** needs no paid key for open instances (see `docs/CONFIGURATION.md`).

## Quick install

```bash
composer require --dev nowo-tech/translation-yaml-tools-bundle
```

Register the bundle in `config/bundles.php` for `dev` (Flex recipe does this). See [Installation](docs/INSTALLATION.md).

## Demos (Symfony 8)

FrankenPHP sample app under [`demo/`](demo/README.md): Web Profiler, **Twig Inspector** (`nowo-tech/twig-inspector-bundle`), explicit `framework.enabled_locales` / `translator` configuration, two translation directories (`translations/` + `translations_extra/`), and a **web page** at `/` that summarizes default locale, enabled locales, YAML paths, missing files per domain, and missing keys vs the default locale. In **dev**, the demo also enables **`missing_translation_log`** (SQLite, **`event_dispatcher`** flush strategy, optional Web UI) — see [`demo/README.md`](demo/README.md).

```bash
make -C demo up-symfony8   # default PORT 8038 — see demo/symfony8/.env.example
```

FrankenPHP worker mode: Supported in production Caddyfile; development uses `Caddyfile.dev` without worker (see [docs/DEMO-FRANKENPHP.md](docs/DEMO-FRANKENPHP.md)).

## Documentation


- [GitHub Actions CI requirements](docs/GITHUB_CI.md)
- [Installation](docs/INSTALLATION.md)
- [Configuration](docs/CONFIGURATION.md)
- [Usage](docs/USAGE.md)
- [Contributing](docs/CONTRIBUTING.md)
- [Code of Conduct](CODE_OF_CONDUCT.md)
- [Changelog](docs/CHANGELOG.md)
- [Upgrading](docs/UPGRADING.md)
- [Release](docs/RELEASE.md)
- [Security](docs/SECURITY.md)
- [Engram](docs/ENGRAM.md)
- [Spec-driven development](docs/SPEC-DRIVEN-DEVELOPMENT.md)
- [GitHub Spec Kit](docs/SPEC-KIT.md)

### Additional documentation

- [Demos](demo/README.md)
- [FrankenPHP demos](docs/DEMO-FRANKENPHP.md)

## Tests and coverage

- **PHP:** run `make test-coverage` and read the final `Global PHP coverage (Lines): …` line in the output (**1.0.0+** maintains **100%** line coverage on `src/` and PHPStan level **8** on **`src/`** + **`tests/`**; see **`docs/CHANGELOG.md`**).
- **TS/JS:** N/A
- **Python:** N/A

## Version information

**1.2.2** is the current stable **1.x** release (PHP **8.2+**, Symfony **7+** / **8.x**). See [UPGRADING](docs/UPGRADING.md) when moving from **1.2.1**, **1.2.0**, **1.1.x**, or earlier.

See [SECURITY POLICY](https://github.com/nowo-tech/TranslationYamlToolsBundle/security/policy) for supported versions.

## License

This bundle is released under the MIT License. See [LICENSE](LICENSE).
