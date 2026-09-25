# FrankenPHP worker mode audit (kernel not reset between requests)

| Field | Value |
|-------|-------|
| Package | `nowo-tech/translation-yaml-tools-bundle` (`symfony-bundle`) |
| Audited revision | `v1.4.5` (working tree → release) |
| Audit date | 2026-09-25 |
| Method | Manual review of every PHP file under `src/` (translator decorator, missing-log recorder, repository, Messenger handler, listeners, Web UI controller, subscriber, Twig extension, machine translators, CLI commands and services, DI extension, compiler passes, `Resources/config/*.yaml`) |
| **Verdict** | ✅ **Viable under scenario B** — the request buffer is emptied on every flush, persistence errors in `kernel.terminate` are logged instead of ending the worker loop, Web UI re-hydrates rows / recovers a closed EntityManager, and DBAL clears detach managed log entities without `kernel.reset` |
| Remediation (2026-09-23→25) | W-01 and W-02 resolved; W-03 flush moved to terminate priority `-1024`; post-delete detach for managed logs (`src/Repository/MissingTranslationLogRepository.php`, `src/MissingTranslationLog/DoctrineMissingTranslationRecorder.php`, `src/Resources/config/services_missing_translation.yaml`). Regression tests: consecutive requests on the same recorder without `reset()` and DBAL writes between reads without `clear()` (`tests/Unit/MissingTranslationLog/DoctrineMissingTranslationRecorderTest.php`, `tests/Unit/Repository/MissingTranslationLogRepositoryTest.php`) |

## Execution model assumed

FrankenPHP worker mode boots the Symfony kernel once per worker and serves many requests with the same container. This audit assumes the **strict** variant: the kernel is **not** rebooted between requests, so every shared service, static property and PHP global survives from one request to the next. Two scenarios are evaluated:

- **A — kernel not rebooted, `services_resetter` still runs:** services tagged `kernel.reset` (or implementing `ResetInterface`) are reset between requests.
- **B — no reset at all:** nothing is reset; any per-request state kept in a service leaks into the next request.

A bundle that is safe under **B** is safe under **A** and under classic mode / PHP-FPM.

Most of the bundle (YAML tree/sort/flatten/audit/fill-missing commands, `Service\*`, `MachineTranslation\*`) is CLI-only and never instantiated by an HTTP request. The runtime surface in the worker is the **missing translation log** feature (`missing_translation_log.enabled: true`), which decorates the `translator` service, plus its optional Web UI.

## Summary

| Area | Status | Notes |
|------|--------|-------|
| Mutable state in shared services | ✅ | Only `DoctrineMissingTranslationRecorder::$buffer` (cleared on terminate and on reset) and `ThrottledMachineTranslator::$lastCallAt` (CLI only) |
| Static properties / `static` locals | ✅ | `TranslationCallSiteResolver` has only pure static methods; no static properties |
| `ResetInterface` / `kernel.reset` coverage | ✅ | `DoctrineMissingTranslationRecorder` implements `ResetInterface`; `reset()` clears the only mutable property |
| Request / user / locale captured in services | ✅ | `RequestStack::getCurrentRequest()`, token storage and translator locale are read per call |
| Superglobals, `$_ENV`, `putenv`, `ini_set`, `setlocale`, timezone | ✅ | None; API keys come from `%env()%` container parameters used by CLI services only |
| Doctrine / EntityManager | ✅ (was ⚠️ Medium) | Bundle methods resolve the manager per call and reset it when closed; Web UI reads use `Query::HINT_REFRESH`. Buffer persistence uses DBAL upserts and does not touch the identity map. After `clearAll` / `clearByStatus`, managed `MissingTranslationLog` entities are detached (ORM 3 has no per-class `clear()`). The bundle never calls `EntityManager::clear()` at runtime |
| Output, headers, `exit`, shutdown functions | ✅ | None in runtime code; `file_put_contents()` only in CLI `TranslationYamlFileHandler` |
| Resources (files, sockets, cURL) held open | ✅ | None |
| Memory growth across requests | ✅ | Buffer is bounded per request (deduplicated by hash) and emptied at `kernel.terminate` |
| Blocking I/O and timeouts | ✅ (was ⚠️ Low) | Sync persistence runs in `kernel.terminate` after the response; exceptions are caught and logged (W-02 resolved). Machine translation HTTP (30 s default, configurable) is CLI only |
| Third-party static state | ✅ | None |
| PHPStan FrankenPHP rulesets | ✅ | `ruleset-classic.neon` + `ruleset-worker.neon` included in `phpstan.neon.dist` |

## Services reviewed

| Service | Shared | Mutable state | Scenario A | Scenario B |
|---------|--------|---------------|------------|------------|
| `Translation\RecordingTranslatorDecorator` (decorates `translator`) | yes | none; `$inner` set once in the constructor; locale is forwarded to the inner translator | ✅ | ✅ |
| `Translation\MissingTranslationLogCallSiteBuilder` | yes | none; reads `RequestStack` per call | ✅ | ✅ |
| `MissingTranslationLog\DoctrineMissingTranslationRecorder` | yes | `$buffer` (per-request missing keys) | ✅ reset + cleared on terminate | ✅ emptied before every flush; flush errors logged, never rethrown |
| `MissingTranslationLog\PersistMissingTranslationBufferMessageHandler`, `MissingTranslationBufferDoctrinePersistListener` | yes | none | ✅ | ✅ |
| `Repository\MissingTranslationLogRepository` | yes | `readonly` registry reference; manager resolved per call | ✅ | ✅ (W-01 resolved) |
| `Doctrine\MissingTranslationLogMetadataListener` | yes | none (`readonly` prefix) | ✅ | ✅ |
| `Controller\MissingTranslationLogUiController` | yes (Web UI only) | none | ✅ | ✅ via repository |
| `EventSubscriber\MissingLogUiAccessSubscriber` | yes | none (`readonly`); reads the token per request | ✅ | ✅ |
| `Security\ConfigurableMissingLogUiAccessChecker` | yes | none; calls `isGranted()` per call | ✅ | ✅ |
| `Twig\MissingTranslationLogExtension` | yes | none; fixed config globals | ✅ | ✅ |
| 7 console commands (+ abstract base), 7 `Service\*` classes, 7 `MachineTranslation\*` classes | yes | none, except `ThrottledMachineTranslator::$lastCallAt` | ✅ N/A (CLI) | ✅ N/A (CLI) |

The entity (`Entity\MissingTranslationLog`), buffer message/event and `MissingTranslationRecordContext` are created per call and never stored in a service.

## Findings

### W-01 — Web UI relies on Doctrine resetting the EntityManager (Medium)

- **Where:** `src/Controller/MissingTranslationLogUiController.php:44` (`findByStatus()`), `:62-68` (`findOneById()` + `flush()`); `src/Repository/MissingTranslationLogRepository.php:37-51`, `:81-84`.
- **Worker impact:** only when `missing_translation_log.web_ui.enabled: true`. Under **A**, DoctrineBundle resets the EntityManager between requests, so this is fine. Under **B**:
  - `MissingTranslationLog` entities loaded by one request stay in the identity map. Hit counts, `last_seen_at` and status are updated with DBAL (`persistBuffer()`, `clearAll()`, `clearByStatus()` at `src/Repository/MissingTranslationLogRepository.php:53-79`, `:94-142`), which bypasses the identity map; later DQL queries return the already-managed objects with their old values, so the admin list can show stale counts, rows already deleted by "clear", or an old status.
  - A failing `flush()` in `markAdded()` closes the EntityManager; every later request in that worker that uses the ORM then fails.
  - The identity map grows for the life of the worker.
  No cross-user leak: the data is a shared, admin-only list.
- **Recommendation:** keep `services_resetter` enabled. If running without any reset, clear the EntityManager per request (e.g. `MissingTranslationLogRepository::clearManaged()` before listing) or set `max_requests`.
- **Status:** Resolved — `src/Repository/MissingTranslationLogRepository.php` keeps its own `ManagerRegistry` reference and every bundle method obtains the manager via `getManagerForClass()` per call; a closed manager is reset (`resetManager()` for its name) before use, and `flush()` resets it when the flush fails and closes it, then rethrows. `findByStatus()` and `findOneById()` use `Query::HINT_REFRESH`, so hit counts / status / deletions written with DBAL are visible to the next request without `clear()`. After DBAL `clearAll` / `clearByStatus`, managed `MissingTranslationLog` instances are detached from the UnitOfWork. `clearManaged()` is kept for BC and is not called at runtime.

### W-02 — Exception during buffer persistence escapes the worker loop (Low)

- **Where:** `src/MissingTranslationLog/DoctrineMissingTranslationRecorder.php:87-115` (`#[AsEventListener(KernelEvents::TERMINATE)]` → `persistBuffer()` when `async_persist` is false or no async path applies).
- **Worker impact:** the Symfony FrankenPHP runner calls `$kernel->terminate()` outside the request handler and without a `try/catch` (see `demo/symfony8/vendor/symfony/runtime/Runner/FrankenPhpWorkerRunner.php:74-76`). If the database is unreachable or the table is missing, the DBAL exception ends the worker script and FrankenPHP restarts it, losing the warm kernel on every request that recorded a missing key. The buffer is emptied before the write (`:94-95`), so no stale data is carried over. Under PHP-FPM the same exception is only logged after the response.
- **Recommendation:** use `async_persist: true` with an async Messenger transport in production, or make sure the missing-log table exists before enabling the feature. A bundle-side improvement would be to catch and log `\Throwable` in `flushBuffer()`.
- **Status:** Resolved — `flushBuffer()` empties the buffer, then wraps sync persistence and async dispatch in `try/catch (\Throwable)` and logs through the optional `LoggerInterface` (new last constructor argument, wired to `@?logger`). Nothing is rethrown from `kernel.terminate`.

### W-03 — Notes (Info)

- Missing keys recorded **after** `flushBuffer()` in the same terminate phase (for example by a later terminate listener that translates an e-mail) stay in the buffer. Under A they are dropped when `services_resetter` calls `reset()` at the start of the next request; under B they are persisted with the next request's flush. Each entry keeps its own route/path fields, so rows are not attributed to the wrong request. **Status:** Accepted, mitigated — the flush listener now runs at priority `DoctrineMissingTranslationRecorder::TERMINATE_PRIORITY` (`-1024`), so ordinary terminate listeners record before the flush.
- `ThrottledMachineTranslator` keeps `$lastCallAt` and may `usleep()` (`src/MachineTranslation/ThrottledMachineTranslator.php:15-35`). It is the `MachineTranslatorInterface` alias but is only injected into `TranslationYamlFillMissingCommand`. Do not inject it into HTTP code: it would pace calls across requests and block the worker thread.
- With `record_call_site: true`, every missing-key hit runs `debug_backtrace()` with 48 frames (`src/Translation/TranslationCallSiteResolver.php:23`). This is CPU cost only, not state.
- The demo production Caddyfile runs in worker mode (`demo/symfony8/docker/frankenphp/Caddyfile`, `worker { ... }`); `Caddyfile.dev` is classic mode.

No High findings: no user, token or request data is kept in any service beyond the request that produced it.

## Usage recommendations in worker mode

- Keeping `services_resetter` / `kernel.reset` active is still recommended for framework services, but the bundle no longer depends on it.
- In production, prefer `missing_translation_log.async_persist: true` with an async transport so no DB write happens in `kernel.terminate` of the HTTP worker.
- Set `record_request_context: false` if request paths must not be stored (privacy); this does not affect worker safety.
- Do not use the machine-translation services (`MachineTranslatorInterface`, `ThrottledMachineTranslator`) from HTTP controllers; they are designed for CLI runs.
- A custom `web_ui.security.access_checker` must stay stateless (or implement `ResetInterface`).

## Re-audit triggers

Re-run this audit when a change adds: new properties to `RecordingTranslatorDecorator` or the recorder; a catalogue or "already recorded" cache that spans requests; a flush point other than `kernel.terminate`; new HTTP-facing services that use the machine translators; ORM writes in `persistBuffer()`; or any use of `$_SERVER` / `$_ENV` at runtime.
