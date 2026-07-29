# Code inventory — 100% traceability

**Baseline spec**: [`spec.md`](spec.md)  
**Package**: `nowo-tech/translation-yaml-tools-bundle`  
**Last audited**: 2026-07-07

## PHP classes (`src/**/*.php`)

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `NowoTranslationYamlToolsBundle.php` | Bundle entry | FR-BUNDLE-001 |
| `DependencyInjection/Configuration.php` | Config tree | FR-CFG-001 |
| `DependencyInjection/NowoTranslationYamlToolsExtension.php` | DI extension | FR-CFG-002 |
| `DependencyInjection/Compiler/TwigPathsPass.php` | Twig paths (Web UI) | FR-TWIG-001 |
| `Service/FrameworkTranslationPathsResolver.php` | Path discovery | FR-YAML-001 |
| `Service/TranslationDefaultLocaleResolver.php` | Default locale | FR-YAML-002 |
| `Service/TranslationYamlCatalog.php` | File catalog | FR-YAML-003 |
| `Service/TranslationYamlFileHandler.php` | YAML I/O | FR-YAML-004 |
| `Service/DotKeyTreeAnalyzer.php` | Tree analysis | FR-YAML-005 |
| `Service/YamlArraySorter.php` | Key sorting | FR-YAML-006 |
| `Service/TranslationYamlAuditor.php` | Audit reports | FR-YAML-007 |
| `Command/AbstractTranslationYamlCommand.php` | CLI base | FR-CLI-001 |
| `Command/TranslationYamlTreeCommand.php` | Tree command | FR-CLI-002 |
| `Command/TranslationYamlFlattenCommand.php` | Flatten command | FR-CLI-002 |
| `Command/TranslationYamlSortCommand.php` | Sort command | FR-CLI-002 |
| `Command/TranslationYamlFillMissingCommand.php` | Fill missing | FR-CLI-002 |
| `Command/TranslationYamlAuditCommand.php` | Audit command | FR-CLI-002 |
| `Command/MissingTranslationLog/MissingTranslationLogListCommand.php` | Log list CLI | FR-CLI-003 |
| `Command/MissingTranslationLog/MissingTranslationLogMarkAddedCommand.php` | Mark added CLI | FR-CLI-003 |
| `Command/MissingTranslationLog/MissingTranslationLogValidateCommand.php` | Validate CLI | FR-CLI-003 |
| `MachineTranslation/MachineTranslatorInterface.php` | MT contract | FR-MT-001 |
| `MachineTranslation/GoogleTranslateMachineTranslator.php` | Google backend | FR-MT-002 |
| `MachineTranslation/DeeplMachineTranslator.php` | DeepL backend | FR-MT-002 |
| `MachineTranslation/LibreTranslateMachineTranslator.php` | LibreTranslate backend | FR-MT-002 |
| `MachineTranslation/MachineTranslationLocaleMapper.php` | Locale mapping | FR-MT-003 |
| `MachineTranslation/RoutingMachineTranslator.php` | MT router | FR-MT-004 |
| `MissingTranslationLog/MissingTranslationRecorderInterface.php` | Recorder IF | FR-MLOG-001 |
| `MissingTranslationLog/DoctrineMissingTranslationRecorder.php` | Doctrine recorder | FR-MLOG-001 |
| `MissingTranslationLog/MissingTranslationBufferDoctrinePersistListener.php` | Event persist | FR-MLOG-004 |
| `MissingTranslationLog/MissingTranslationBufferEvent.php` | Buffer event | FR-MLOG-004 |
| `MissingTranslationLog/MissingTranslationBufferMessage.php` | Buffer message | FR-MLOG-004 |
| `MissingTranslationLog/PersistMissingTranslationBufferMessageHandler.php` | Messenger handler | FR-MLOG-004 |
| `Translation/RecordingTranslatorDecorator.php` | Translator decorator | FR-MLOG-002 |
| `Translation/MissingTranslationRecordContext.php` | Record context | FR-MLOG-003 |
| `Translation/MissingTranslationLogCallSiteBuilder.php` | Call site builder | FR-MLOG-003 |
| `Translation/TranslationCallSiteResolver.php` | Call site resolver | FR-MLOG-003 |
| `Entity/MissingTranslationLog.php` | Log entity | FR-MLOG-005 |
| `Entity/MissingTranslationLogStatus.php` | Status enum | FR-MLOG-005 |
| `Doctrine/MissingTranslationLogMetadataListener.php` | Table prefix | FR-MLOG-005 |
| `Repository/MissingTranslationLogRepository.php` | Log repository | FR-MLOG-006 |
| `Controller/MissingTranslationLogUiController.php` | Web UI controller | FR-MLOG-007 |
| `EventSubscriber/MissingLogUiAccessSubscriber.php` | UI access guard | FR-MLOG-009 |
| `Twig/MissingTranslationLogExtension.php` | Twig helpers | FR-MLOG-009 |

## Symfony config (`src/Resources/config/`)

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `services.yaml` | Core wiring | FR-DI-001 |
| `services_missing_translation.yaml` | Missing log services | FR-DI-002 |
| `services_missing_translation_event_dispatcher.yaml` | Event flush | FR-DI-002 |
| `services_missing_translation_messenger.yaml` | Messenger flush | FR-DI-002 |
| `services_missing_translation_web.yaml` | Web UI services | FR-DI-002 |
| `routes/missing_translation_log_ui.yaml` | Web UI routes | FR-MLOG-007 |

## Twig views (`src/Resources/views/missing_translation_log/`)

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `base.html.twig` | Base template | FR-MLOG-008 |
| `layout.html.twig` | Default layout | FR-MLOG-008 |
| `layout_integrate_dashboard_menu.html.twig` | Dashboard layout | FR-MLOG-008 |
| `layout_integrate_breadcrumb_kit.html.twig` | Breadcrumb layout | FR-MLOG-008 |
| `index.html.twig` | Index page | FR-MLOG-008 |
| `_table.html.twig` | Log table partial | FR-MLOG-008 |
| `_status_filters.html.twig` | Status filters | FR-MLOG-008 |

## Coverage summary

| Category | Files | Mapped |
| --- | ---: | ---: |
| PHP classes | 43 | 43 |
| YAML config | 6 | 6 |
| Twig views | 7 | 7 |
| **Total production sources** | **56** | **56** |

## Inventory refresh (2026-07-29 remedia)

Files present under `src/` that must stay listed for REQ-SPECKIT-003:

- `DependencyInjection/Compiler/MissingLogWebUiSecurityPass.php`
- `MachineTranslation/LibreTranslateBaseUrlGuard.php`
- `MachineTranslation/ThrottledMachineTranslator.php`
- `Resources/config/routes/missing_translation_log_ui.yaml`
- `Resources/config/services.yaml`
- `Resources/config/services_missing_translation.yaml`
- `Resources/config/services_missing_translation_event_dispatcher.yaml`
- `Resources/config/services_missing_translation_messenger.yaml`
- `Resources/config/services_missing_translation_web.yaml`
- `Resources/views/missing_translation_log/_status_filters.html.twig`
- `Resources/views/missing_translation_log/_table.html.twig`
- `Resources/views/missing_translation_log/base.html.twig`
- `Resources/views/missing_translation_log/index.html.twig`
- `Resources/views/missing_translation_log/layout.html.twig`
- `Resources/views/missing_translation_log/layout_integrate_breadcrumb_kit.html.twig`
- `Resources/views/missing_translation_log/layout_integrate_dashboard_menu.html.twig`
- `Security/ConfigurableMissingLogUiAccessChecker.php`
- `Security/MissingLogUiAccessCheckerInterface.php`
