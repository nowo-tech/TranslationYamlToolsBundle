# Code inventory — 100% traceability

**Baseline spec**: [`spec.md`](spec.md)  
**Package**: `nowo-tech/translation-yaml-tools-bundle`  
**Last audited**: 2026-10-20 (text overrides 002 and strict parity 003 folded into the baseline; audit: `git ls-files src | wc -l` → 85)

Paths are relative to `src/`. Every tracked file under `src/` is a production unit (no build outputs, no co-located tests).

## Bundle & DI

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `DependencyInjection/Compiler/MissingLogWebUiSecurityPass.php` | Web UI SecurityBundle guard | FR-SEC-001 |
| `DependencyInjection/Compiler/TextOverrideWebUiPass.php` | Overrides Web UI dependency guard | FR-OVR-005 |
| `DependencyInjection/Compiler/TwigPathsPass.php` | Twig paths (Web UI) | FR-TWIG-001 |
| `DependencyInjection/Configuration.php` | Config tree | FR-CFG-001 |
| `DependencyInjection/NowoTranslationYamlToolsExtension.php` | DI extension | FR-CFG-002 |
| `NowoTranslationYamlToolsBundle.php` | Bundle entry | FR-BUNDLE-001 |

## YAML services & parity

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Service/DotKeyTreeAnalyzer.php` | Tree analysis | FR-YAML-005 |
| `Service/FrameworkTranslationPathsResolver.php` | Path discovery | FR-YAML-001 |
| `Service/TranslationDefaultLocaleResolver.php` | Default locale | FR-YAML-002 |
| `Service/TranslationParityChecker.php` | Strict parity checks | FR-PARITY-001 |
| `Service/TranslationYamlAuditor.php` | Audit reports | FR-YAML-007, FR-PARITY-001 |
| `Service/TranslationYamlCatalog.php` | File catalog | FR-YAML-003 |
| `Service/TranslationYamlFileHandler.php` | YAML I/O | FR-YAML-004 |
| `Service/YamlArraySorter.php` | Key sorting | FR-YAML-006 |
| `Testing/TranslationParityAssertions.php` | PHPUnit parity trait | FR-PARITY-002 |

## Console commands

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Command/AbstractTranslationYamlCommand.php` | CLI base | FR-CLI-001 |
| `Command/MissingTranslationLog/MissingTranslationLogListCommand.php` | Log list CLI | FR-CLI-003 |
| `Command/MissingTranslationLog/MissingTranslationLogMarkAddedCommand.php` | Mark added CLI | FR-CLI-003 |
| `Command/MissingTranslationLog/MissingTranslationLogValidateCommand.php` | Validate CLI | FR-CLI-003 |
| `Command/TranslationYamlAuditCommand.php` | Audit command | FR-CLI-002 |
| `Command/TranslationYamlFillMissingCommand.php` | Fill missing | FR-CLI-002 |
| `Command/TranslationYamlFlattenCommand.php` | Flatten command | FR-CLI-002 |
| `Command/TranslationYamlSortCommand.php` | Sort command | FR-CLI-002 |
| `Command/TranslationYamlTreeCommand.php` | Tree command | FR-CLI-002 |

## Machine translation

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `MachineTranslation/DeeplMachineTranslator.php` | DeepL backend | FR-MT-002 |
| `MachineTranslation/GoogleTranslateMachineTranslator.php` | Google backend | FR-MT-002 |
| `MachineTranslation/LibreTranslateBaseUrlGuard.php` | LibreTranslate host allowlist | FR-MT-006 |
| `MachineTranslation/LibreTranslateMachineTranslator.php` | LibreTranslate backend | FR-MT-002 |
| `MachineTranslation/MachineTranslationLocaleMapper.php` | Locale mapping | FR-MT-003 |
| `MachineTranslation/MachineTranslatorInterface.php` | MT contract | FR-MT-001 |
| `MachineTranslation/RoutingMachineTranslator.php` | MT router | FR-MT-004 |
| `MachineTranslation/ThrottledMachineTranslator.php` | MT call pacing | FR-MT-005 |

## Missing-translation log (PHP)

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Controller/MissingTranslationLogUiController.php` | Web UI controller | FR-MLOG-007 |
| `Doctrine/MissingTranslationLogMetadataListener.php` | Table prefix | FR-MLOG-005 |
| `Entity/MissingTranslationLog.php` | Log entity | FR-MLOG-005 |
| `Entity/MissingTranslationLogStatus.php` | Status enum | FR-MLOG-005 |
| `EventSubscriber/MissingLogUiAccessSubscriber.php` | UI access guard | FR-MLOG-009 |
| `MissingTranslationLog/DoctrineMissingTranslationRecorder.php` | Doctrine recorder | FR-MLOG-001, FR-MLOG-010, FR-WORKER-001 |
| `MissingTranslationLog/MissingTranslationBufferDoctrinePersistListener.php` | Event persist | FR-MLOG-004 |
| `MissingTranslationLog/MissingTranslationBufferEvent.php` | Buffer event | FR-MLOG-004 |
| `MissingTranslationLog/MissingTranslationBufferMessage.php` | Buffer message | FR-MLOG-004 |
| `MissingTranslationLog/MissingTranslationRecorderInterface.php` | Recorder IF | FR-MLOG-001 |
| `MissingTranslationLog/PersistMissingTranslationBufferMessageHandler.php` | Messenger handler | FR-MLOG-004 |
| `Repository/MissingTranslationLogRepository.php` | Log repository | FR-MLOG-006, FR-WORKER-001 |
| `Security/ConfigurableMissingLogUiAccessChecker.php` | Role-based UI access checker | FR-SEC-001 |
| `Security/MissingLogUiAccessCheckerInterface.php` | UI access checker contract | FR-SEC-001 |
| `Translation/MissingTranslationLogCallSiteBuilder.php` | Call site builder | FR-MLOG-003 |
| `Translation/MissingTranslationRecordContext.php` | Record context | FR-MLOG-003 |
| `Translation/RecordingTranslatorDecorator.php` | Translator decorator | FR-MLOG-002 |
| `Translation/TranslationCallSiteResolver.php` | Call site resolver | FR-MLOG-003 |
| `Twig/MissingTranslationLogExtension.php` | Twig helpers | FR-MLOG-009 |

## Text overrides (PHP)

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `TextOverride/Controller/TranslationOverrideController.php` | Overrides desk controller | FR-OVR-005 |
| `TextOverride/Doctrine/TranslationOverrideMetadataListener.php` | Override table mapping | FR-OVR-001 |
| `TextOverride/EditableTranslationKeys.php` | Editable key set | FR-OVR-003 |
| `TextOverride/Entity/TranslationOverride.php` | Override entity | FR-OVR-001 |
| `TextOverride/Html/StripTagsTranslationOverrideHtmlSanitizer.php` | Fallback sanitizer | FR-OVR-004 |
| `TextOverride/Html/SymfonyTranslationOverrideHtmlSanitizer.php` | Default sanitizer | FR-OVR-004 |
| `TextOverride/Html/TranslationOverrideHtmlSanitizerInterface.php` | Sanitizer contract | FR-OVR-004 |
| `TextOverride/OverridingTranslator.php` | Translator decorator | FR-OVR-002 |
| `TextOverride/Repository/TranslationOverrideRepository.php` | Override repository | FR-OVR-006, FR-WORKER-001 |
| `TextOverride/TranslationOverrideEditor.php` | Per-locale editor / form bridge | FR-OVR-003 |
| `TextOverride/TranslationOverrideLocales.php` | Editor locales | FR-OVR-003 |
| `TextOverride/TranslationOverrideProviderInterface.php` | Override read contract | FR-OVR-002 |
| `TextOverride/TranslationOverrides.php` | Override read/write + cache | FR-OVR-002 |

## Symfony config

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Resources/config/routes/missing_translation_log_ui.yaml` | Web UI routes | FR-MLOG-007 |
| `Resources/config/routes/translation_override_ui.yaml` | Overrides Web UI routes | FR-OVR-005 |
| `Resources/config/services.yaml` | Core wiring | FR-DI-001 |
| `Resources/config/services_missing_translation.yaml` | Missing log services | FR-DI-002 |
| `Resources/config/services_missing_translation_event_dispatcher.yaml` | Event flush | FR-DI-002 |
| `Resources/config/services_missing_translation_messenger.yaml` | Messenger flush | FR-DI-002 |
| `Resources/config/services_missing_translation_web.yaml` | Web UI services | FR-DI-002 |
| `Resources/config/services_overrides.yaml` | Overrides runtime services | FR-OVR-001, FR-OVR-002 |
| `Resources/config/services_overrides_web.yaml` | Overrides Web UI services | FR-OVR-005 |

## Twig views — missing-translation log

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Resources/views/missing_translation_log/_status_filters.html.twig` | Status filters | FR-MLOG-008 |
| `Resources/views/missing_translation_log/_table.html.twig` | Log table partial | FR-MLOG-008 |
| `Resources/views/missing_translation_log/base.html.twig` | Base template | FR-MLOG-008 |
| `Resources/views/missing_translation_log/index.html.twig` | Index page | FR-MLOG-008 |
| `Resources/views/missing_translation_log/layout.html.twig` | Default layout | FR-MLOG-008 |
| `Resources/views/missing_translation_log/layout_integrate_breadcrumb_kit.html.twig` | Breadcrumb layout | FR-MLOG-008 |
| `Resources/views/missing_translation_log/layout_integrate_dashboard_menu.html.twig` | Dashboard layout | FR-MLOG-008 |

## Twig views — text overrides

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Resources/views/text_override/edit.html.twig` | Override edit page | FR-OVR-005 |
| `Resources/views/text_override/index.html.twig` | Override desk list | FR-OVR-005 |
| `Resources/views/text_override/layout.html.twig` | Override desk layout | FR-OVR-005 |

## Translations

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Resources/translations/NowoTranslationYamlToolsBundle.en.yaml` | UI strings (en) | FR-I18N-001 |
| `Resources/translations/NowoTranslationYamlToolsBundle.es.yaml` | UI strings (es) | FR-I18N-001 |
| `Resources/translations/NowoTranslationYamlToolsBundle.it.yaml` | UI strings (it) | FR-I18N-001 |
| `Resources/translations/NowoTranslationYamlToolsBundle.fr.yaml` | UI strings (fr) | FR-I18N-001 |
| `Resources/translations/NowoTranslationYamlToolsBundle.pt.yaml` | UI strings (pt) | FR-I18N-001 |
| `Resources/translations/NowoTranslationYamlToolsBundle.de.yaml` | UI strings (de) | FR-I18N-001 |
| `Resources/translations/NowoTranslationYamlToolsBundle.nl.yaml` | UI strings (nl) | FR-I18N-001 |

## Coverage summary

| Category | Files | Mapped |
| --- | ---: | ---: |
| Bundle & DI | 6 | 6 |
| YAML services & parity | 9 | 9 |
| Console commands | 9 | 9 |
| Machine translation | 8 | 8 |
| Missing-translation log (PHP) | 19 | 19 |
| Text overrides (PHP) | 13 | 13 |
| Symfony config | 9 | 9 |
| Twig views — missing-translation log | 7 | 7 |
| Twig views — text overrides | 3 | 3 |
| Translations | 2 | 2 |
| **Total production sources** | **85** | **85** |
