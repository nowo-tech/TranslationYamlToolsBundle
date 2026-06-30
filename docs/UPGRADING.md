# Upgrading

## 0.x

This line is **0.3.x** (after **0.2.0**). Treat minor and patch releases as potentially containing small command output or behaviour tweaks until a stable **`1.0.0`** is tagged. Always read `docs/CHANGELOG.md` before upgrading.

## To 0.3.10 (from 0.3.9)

- **Optional:** tag **0.3.10** refreshes the **CI Symfony matrix**, **`symfony/var-exporter`** dev constraint, and **demo** lockfiles (**Symfony 7.4** / **8.1**). Applications depending on **`nowo-tech/translation-yaml-tools-bundle`** behave the same as **0.3.9**; bump only if you want the tag to match **`main`** after those maintenance commits.

## To 0.3.9 (from 0.3.8)

- Upgrade with **`composer update nowo-tech/translation-yaml-tools-bundle`** (constraint **`^0.3`** already allows **0.3.9**).
- **Recommended** if **`missing_translation_log.enabled`** is **`true`** and you use **LexikTranslationBundle** (or any bundle that calls extra methods on the **`translator`** service, such as **`getFormats()`**): **0.3.9** makes **`RecordingTranslatorDecorator`** transparent for those calls. No YAML or migration changes.

## To 0.3.8 (from 0.3.7)

- **Optional:** tag **0.3.8** only refreshes **demo** lockfiles and **`demo/symfony8/config/reference.php`**. Applications depending on **`nowo-tech/translation-yaml-tools-bundle`** behave the same as **0.3.7**; bump only if you want the tag to match **`main`** after those demo-only commits.

## To 0.3.7 (from 0.3.6)

- Upgrade with **`composer update nowo-tech/translation-yaml-tools-bundle`** (constraint **`^0.3`** already allows **0.3.7**).
- **Deprecation fix only:** constructor parameter order in **`RecordingTranslatorDecorator`** was adjusted to avoid PHP runtime deprecations.
- If you instantiate **`RecordingTranslatorDecorator`** manually with positional arguments in custom code/tests, update calls to:
  - `new RecordingTranslatorDecorator($inner, $recorder, $callSiteBuilder, $recordCallSite, $recordRequestContext)`

## To 0.3.6 (from 0.3.5)

- Upgrade with **`composer update nowo-tech/translation-yaml-tools-bundle`** (constraint **`^0.3`** already allows **0.3.6**).
- **Missing-log API:** if you implement **`MissingTranslationRecorderInterface`** yourself, remove the previously introduced optional Twig-template argument from **`record()`** so your signature matches **0.3.6**.
- **Database:** runtime persistence no longer writes **`twig_template`**. Keeping that nullable column in existing tables is harmless. If you want a fully clean schema, drop it in your migration:
  - MySQL/MariaDB: `ALTER TABLE <prefix>missing_log DROP COLUMN twig_template;`
  - PostgreSQL: `ALTER TABLE <prefix>missing_log DROP COLUMN twig_template;`
  - SQLite: recreate table via migration / Doctrine diff as usual.
- **UI/ops:** missing-log Web UI now includes two reset actions (**clear all**, **clear current status**) so manual SQL cleanup is usually unnecessary for day-to-day usage.

## To 0.3.5 (from 0.3.4)

- Upgrade with **`composer update nowo-tech/translation-yaml-tools-bundle`** (constraint **`^0.3`** already allows **0.3.5**).
- **Database:** when **`missing_translation_log.enabled`** is **`true`**, run a migration or **`doctrine:schema:update`** so table **`{table_prefix}missing_log`** gains **`request_route`**, **`request_method`**, and **`request_path`** (nullable strings). Existing rows keep whatever **`call_site`** already contained; new hits store backtrace only in **`call_site`** and HTTP data in the new columns.
- **PHP:** if you implement **`MissingTranslationRecorderInterface`** yourself, extend **`record()`** with the three new optional parameters (same defaults as the bundle’s **`DoctrineMissingTranslationRecorder`**).
- **`MissingTranslationBufferMessage` / `MissingTranslationBufferEvent`:** buffered rows include **`requestRoute`**, **`requestMethod`**, and **`requestPath`** keys when async persist is used.

## To 0.3.4 (from 0.3.3)

- **No breaking changes for apps that do not customize the missing-log recorder.** Upgrade with **`composer update nowo-tech/translation-yaml-tools-bundle`** (constraint **`^0.3`** already allows **0.3.4**).
- **`sort`**, **`flatten`**, **`tree`:** if you relied on **non-interactive** mode requiring **`--locale`**, **`--domain` alone** now runs the command for **all** locales of that domain. Pass **`--locale`** when you need a **single** file (same as before).
- **`tree`:** optional **`--fix-leaf-prefix`** and config **`yaml_tree_leaf_prefix_suffix`** (default **`index`**) — see **`docs/USAGE.md`** and **`docs/CONFIGURATION.md`**.

## To 0.3.3 (from 0.3.2)

- **No configuration or migration changes** are required. Upgrade with **`composer update nowo-tech/translation-yaml-tools-bundle`** (constraint **`^0.3`** already allows **0.3.3**).
- **0.3.3** is primarily test-suite and internal clean-up (full line coverage on **`src/`**, **`TranslationCallSiteResolver`** / extension refactors, **`persistBuffer`** column-name fix). Runtime behaviour for normal bundle usage matches **0.3.2**.
- **`DotKeyTreeAnalyzer`**, **`YamlArraySorter`**, and **`TranslationDefaultLocaleResolver`** are no longer declared **`final`**. This does not change supported configuration or services; it only matters if custom tooling assumed **`final`** for those classes (subclassing them was never part of the public API).

## To 0.3.2 (from 0.3.1)

- **Recommended** if **`missing_translation_log.enabled`** is **`true`**: **0.3.2** fixes duplicate-key database errors when the same missing translation is recorded concurrently (e.g. parallel HTTP requests or multiple workers flushing the buffer). Persistence is unchanged from an app-configuration perspective: no migration beyond what you already have for **`{table_prefix}missing_log`**, and no YAML changes. Upgrade with **`composer update nowo-tech/translation-yaml-tools-bundle`** (constraint **`^0.3`** already allows **0.3.2**).
- If you subclass or replace **`MissingTranslationLogRepository`**, ensure your **`persistBuffer`** implementation remains consistent with the unique index on **`message_id`**, **`domain`**, and **`locale`** (or call the parent).

## To 0.3.1 (from 0.3.0)

- **Strongly recommended** if **`missing_translation_log.web_ui.enabled`** is **`true`**: **0.3.0** registered the bundle Twig namespace against **`…/translation-yaml-tools-bundle/Resources/views`**, which does not exist in the Composer package (views live under **`src/Resources/views`**). That breaks **`bin/console cache:clear`** when Twig warms the native filesystem loader. **0.3.1** fixes the path and keeps **REQ-TWIG-001**: when **`templates/bundles/NowoTranslationYamlToolsBundle/`** exists, the bundle calls **`prependPath()`** on the Twig loader so overrides win without **`twig.paths`**. Upgrade with **`composer update nowo-tech/translation-yaml-tools-bundle`** (constraint **`^0.3`** already allows **0.3.1**).
- No YAML, route, or PHP API changes are required beyond updating the dependency.
- If you added **`twig.paths`** entries **only** to work around the **0.3.0** path error, you can remove them after upgrading; the compiler pass registers the namespace when the Web UI is enabled.

## To 0.3.0 (from 0.2.x)

- In Composer, require **`^0.3`** (or pin **`0.3.x`**) to receive this line; **`^0.2`** does not pull **0.3.0**.
- Optional **`missing_translation_log`**: requires **`doctrine/orm`** and **`doctrine/doctrine-bundle`** in the app. Table name is **`{table_prefix}missing_log`** (default prefix **`nowo_translation_`**). The bundle prepends Doctrine mapping when **`enabled: true`**. See **`docs/CONFIGURATION.md`** (“Missing translation log”).
- Optional **`missing_translation_log.web_ui`**: requires **`symfony/twig-bundle`**, importing the bundle routes, and **`framework.csrf_protection`** + **`symfony/security-csrf`** for the **Mark added** form. The Flex recipe ships a **dev-only** route import. Use **0.3.1+** for a correct Twig loader path (see **To 0.3.1** above).
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
