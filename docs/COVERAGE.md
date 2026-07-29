# Test coverage

This bundle targets **≥ 99%** PHP line coverage on includable `src/` (see `make test-coverage` / `composer test-coverage`).

## PHPUnit source include / exclude

Configured in [`phpunit.xml.dist`](../phpunit.xml.dist):

| Path | Reason |
|------|--------|
| `src/**` (include) | Production code under coverage |
| `src/MachineTranslation/MachineTranslatorInterface.php` (exclude) | Interface-only; no executable statements worth attributing |

Other interfaces under `src/` (e.g. `MissingTranslationRecorderInterface`, `MissingLogUiAccessCheckerInterface`) remain included; they contribute negligible or zero executable lines.

## Justified remaining misses / ignores

These branches use `@codeCoverageIgnore` (same pattern as ProgressBar TTY paths in `TranslationYamlFillMissingCommand`) because they are unreachable or not reproducible in PHPUnit without brittle filesystem races:

| Location | Why ignored |
|----------|-------------|
| `NowoTranslationYamlToolsBundle::getContainerExtension()` — `LogicException` when `$extension` is not an `ExtensionInterface` | Defensive guard. Symfony’s `Bundle::$extension` is typed `ExtensionInterface\|false\|null`, so an invalid value cannot be assigned (including via reflection) on PHP 8.2+. |
| `TranslationYamlFileHandler::loadFile()` — `filesize($path) === false` after `is_file($path)` | On normal filesystems, a path that passes `is_file()` has a readable size. Stream wrappers that fail `stat` also fail `is_file()`. |
| `TranslationYamlFillMissingCommand::assertPathUnderTranslationDirectories()` — `realpath($parent) === false` after a successful `mkdir` / `is_dir` | Not reproducible on standard OS path resolution after the directory exists. |

Platform-specific upsert paths in `MissingTranslationLogRepository` (`upsertBufferRowMySql`, `upsertBufferRowFallback` / `updateExistingBufferRow`) are covered by unit tests that inject a mocked DBAL `Connection` with `MySQLPlatform` or a non-SQLite/non-MySQL/non-PostgreSQL platform (e.g. `OraclePlatform`), while integration-style tests keep using in-memory SQLite (`MissingTranslationLogTestEntityManagerFactory`).

## How to verify

```bash
make test-coverage
```

Expect **Global PHP coverage (Lines)** ≥ **99.00**.
