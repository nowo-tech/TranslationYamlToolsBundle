# FrankenPHP worker mode audit (kernel not reset between requests)

| Field | Value |
|-------|-------|
| Package | `nowo-tech/translation-yaml-tools-bundle` (`symfony-bundle`) |
| Audited revision | `v1.4.5` (working tree → release); text overrides re-audited for `v1.17.1` (REQ-RUNTIME-002) |
| Audit date | 2026-09-25; overrides 2026-11-12 |
| Method | Manual review of every PHP file under `src/` (translator decorator, missing-log recorder, repository, Messenger handler, listeners, Web UI controller, subscriber, Twig extension, machine translators, CLI commands and services, DI extension, compiler passes, `Resources/config/*.yaml`) |
| **Verdict** | ✅ **Viable under scenario B** — the request buffer is emptied on every flush, persistence errors in `kernel.terminate` are logged instead of ending the worker loop, Web UI re-hydrates rows / recovers a closed EntityManager, and DBAL clears detach managed log entities without `kernel.reset` |
| Remediation (2026-11-12, 1.17.1) | W-04 resolved: the override map memo is dropped at the request, Messenger and console boundaries by `TranslationOverridesClearSubscriber`, without relying on `kernel.reset`. Worker test: `tests/Functional/Worker/WorkerBoundariesTest.php` |
| Remediation (2026-09-23→25) | W-01 and W-02 resolved; W-03 flush moved to terminate priority `-1024`; post-delete detach for managed logs (`src/Repository/MissingTranslationLogRepository.php`, `src/MissingTranslationLog/DoctrineMissingTranslationRecorder.php`, `src/Resources/config/services_missing_translation.yaml`). Regression tests: consecutive requests on the same recorder without `reset()` and DBAL writes between reads without `clear()` (`tests/Unit/MissingTranslationLog/DoctrineMissingTranslationRecorderTest.php`, `tests/Unit/Repository/MissingTranslationLogRepositoryTest.php`) |

## Table of contents

- [Execution model assumed](#execution-model-assumed)
- [Summary](#summary)
- [Services reviewed](#services-reviewed)
- [Findings](#findings)
  - [W-01 — Web UI relies on Doctrine resetting the EntityManager (Medium)](#w-01--web-ui-relies-on-doctrine-resetting-the-entitymanager-medium)
  - [W-02 — Exception during buffer persistence escapes the worker loop (Low)](#w-02--exception-during-buffer-persistence-escapes-the-worker-loop-low)
  - [W-03 — Notes (Info)](#w-03--notes-info)
  - [W-04 — Text override map memo cleared only by kernel.reset (Low)](#w-04--text-override-map-memo-cleared-only-by-kernelreset-low)
- [Worker test](#worker-test)
- [Usage recommendations in worker mode](#usage-recommendations-in-worker-mode)
- [Re-audit triggers](#re-audit-triggers)

## Execution model assumed

FrankenPHP worker mode boots the Symfony kernel once per worker and serves many requests with the same container. This audit assumes the **strict** variant: the kernel is **not** rebooted between requests, so every shared service, static property and PHP global survives from one request to the next. Two scenarios are evaluated:

- **A — kernel not rebooted, `services_resetter` still runs:** services tagged `kernel.reset` (or implementing `ResetInterface`) are reset between requests.
- **B — no reset at all:** nothing is reset; any per-request state kept in a service leaks into the next request.

Precisely: with the stock Symfony kernel, `FRANKENPHP_RESET_KERNEL=false` only stops the kernel from being cloned; `Kernel::handle()` still calls `services_resetter->reset()` before every request after the first (scenario A). Scenario B happens where the resetter does not run: a host that removes, replaces or bypasses `services_resetter` (or drives `http_kernel` directly), Messenger consumers started with `--no-reset`, and long-running console commands. The bundle is correct under B (REQ-RUNTIME-002).

A bundle that is safe under **B** is safe under **A** and under classic mode / PHP-FPM.

Most of the bundle (YAML tree/sort/flatten/audit/fill-missing commands, `Service\*`, `MachineTranslation\*`) is CLI-only and never instantiated by an HTTP request. The runtime surface in the worker is the **missing translation log** feature (`missing_translation_log.enabled: true`), which decorates the `translator` service, plus its optional Web UI, and the **text overrides** feature (`overrides.enabled: true`), whose `OverridingTranslator` decorates the `translator` and reads `TranslationOverrides`.

## Summary

| Area | Status | Notes |
|------|--------|-------|
| Mutable state in shared services | ✅ | `DoctrineMissingTranslationRecorder::$buffer` (cleared on terminate and on reset), `TranslationOverrides::$map` (dropped at every request / Messenger / console boundary by `TranslationOverridesClearSubscriber`, and on reset; W-04), `TranslationOverridesClearSubscriber::$consoleDepth` / `$messageInFlight` (nesting counter and in-flight flag, no request data) and `ThrottledMachineTranslator::$lastCallAt` (CLI only) |
| Static properties / `static` locals | ✅ | `TranslationCallSiteResolver` has only pure static methods; no static properties |
| `ResetInterface` / `kernel.reset` coverage | ✅ | `DoctrineMissingTranslationRecorder` and `TranslationOverrides` implement `ResetInterface`; `reset()` clears their mutable property and is tested directly (`DoctrineMissingTranslationRecorderTest`, `TranslationOverridesTest::testResetDropsTheMemoSoAnotherWorkersEditIsSeen`). Neither relies on it alone |
| Request / user / locale captured in services | ✅ | `RequestStack::getCurrentRequest()`, token storage and translator locale are read per call |
| Superglobals, `$_ENV`, `putenv`, `ini_set`, `setlocale`, timezone | ✅ | None; API keys come from `%env()%` container parameters used by CLI services only |
| Doctrine / EntityManager | ✅ (was ⚠️ Medium) | Bundle methods resolve the manager per call and reset it when closed; Web UI reads use `Query::HINT_REFRESH`. Buffer persistence uses DBAL upserts and does not touch the identity map. After `clearAll` / `clearByStatus`, managed `MissingTranslationLog` entities are detached (ORM 3 has no per-class `clear()`). The bundle never calls `EntityManager::clear()` at runtime |
| Output, headers, `exit`, shutdown functions | ✅ | None in runtime code; `file_put_contents()` only in CLI `TranslationYamlFileHandler` |
| Resources (files, sockets, cURL) held open | ✅ | None |
| Memory growth across requests | ✅ | Buffer is bounded per request (deduplicated by hash) and emptied at `kernel.terminate` (also `console.terminate` and after each Messenger worker message, before `services_resetter` runs; same catch-and-log flush) |
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
| `TextOverride\OverridingTranslator` (decorates `translator`) | yes | none; reads `TranslationOverrides` through a service closure per call | ✅ | ✅ |
| `TextOverride\TranslationOverrides` | yes | `$map` (override map memo) | ✅ reset | ✅ since 1.17.1, dropped at every boundary (W-04) |
| `TextOverride\EventSubscriber\TranslationOverridesClearSubscriber` | yes | `$consoleDepth`, `$messageInFlight` (no request data) | ✅ | ✅ |
| `TextOverride\Repository\TranslationOverrideRepository`, `TranslationOverrideEditor`, Web UI controller | yes | none; manager resolved per call, closed manager reset by name | ✅ | ✅ |
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
- **Status:** Resolved — `src/Repository/MissingTranslationLogRepository.php` keeps its own `ManagerRegistry` reference and every bundle method obtains the manager via `getManagerForClass()` per call; a closed manager is reset (`resetManager()` for its name) before use, and `flush()` resets it when the flush fails and closes it, then rethrows. `findByStatus()` and `findOneById()` use `Query::HINT_REFRESH`, so hit counts / status / deletions written with DBAL are visible to the next request without `clear()`. After DBAL `clearAll` / `clearByStatus`, managed `MissingTranslationLog` instances are detached from the UnitOfWork. `clearManaged()` is kept for BC and is not called at runtime. Since 1.17.0 `TranslationOverrideRepository` also resets its closed manager by name (it used to call `resetManager()` without a name, i.e. the host's default manager); kept-alive kernel test without `kernel.reset`: `tests/Functional/Worker/WorkerEntityManagerRecoveryTest.php`.

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

### W-04 — Text override map memo cleared only by kernel.reset (Low)

- **Where:** `src/TextOverride/TranslationOverrides.php` (`$map`, filled by `all()`).
- **Worker impact (1.6.0–1.17.0):** under **B** the memo was dropped only by `reset()` (`kernel.reset`) or by `invalidate()` after a write through the same instance. A worker kept the map it loaded first and never saw overrides saved, changed or removed by another worker or process (the shared cache entry was dropped, but the in-memory memo was not), until it saved an override itself. No cross-user data: the map is the same for every user. Under **A** (stock `Kernel::handle()`) there was no effect.
- **Status:** Resolved in 1.17.1 — `TextOverride\EventSubscriber\TranslationOverridesClearSubscriber` (registered by the bundle when overrides are enabled) calls `reset()` at the start of every main request (priority 4096) and at `kernel.terminate`; on `WorkerMessageReceivedEvent` (4096) and `WorkerMessageHandledEvent` / `WorkerMessageFailedEvent` (-4096); and on `console.command` (4096) / `console.terminate` (-4096) for the outermost command only, outside a Messenger message. A command run in-process from another command or from a message handler (`Application::doRun()`) keeps the outer unit's memo (console depth counter plus an in-flight flag, unset on handled, failed and `WorkerStoppedEvent`; same pattern as BeaconBundle 1.24.0). The memo arrives as an `IGNORE_ON_UNINITIALIZED_REFERENCE` reference, so a boundary never builds it. The Messenger events are subscribed by name (symfony/messenger stays optional).

No High findings: no user, token or request data is kept in any service beyond the request that produced it.

## Worker test

`tests/Functional/Worker/WorkerBoundariesTest.php` (kernel `tests/Kernel/WorkerBoundariesTestKernel.php`) boots **one** kernel and never calls the services resetter:

- **HTTP:** 500 requests through the `http_kernel` service (not `Kernel::handle()`, so no resetter), with `terminate()`;
- **Messenger:** 500 messages through a real `Worker` without `ResetServicesListener` (asserted), every 7th handler failing;
- **console:** 500 `Application::run()` calls;
- tenants A (English) and B (Spanish) alternate; before every unit, another worker edits both overrides in the database and drops the shared cache entry. Each unit must render its own locale's latest text. Memory margin: 1 MiB after a 50-unit warm-up;
- nested `doRun()` from a command and from a message handler keeps the outer unit's memo; the outermost boundary still drops it.

Without the subscriber, all five tests fail (the units keep rendering revision 0). `tests/Functional/Worker/WorkerEntityManagerRecoveryTest.php` covers the closed-manager recovery (20 requests). Static checks: `composer igor` (CI) and the PHPStan FrankenPHP worker ruleset (`phpstan.neon.dist`) pass.

## Usage recommendations in worker mode

- Keeping `services_resetter` / `kernel.reset` active is still recommended for framework services, but the bundle no longer depends on it.
- **Host rule (REQ-RUNTIME-002 rule 7):** a host that disables or bypasses the services resetter must also clear its own entity managers at every boundary (main request start or end, Messenger message, console command); otherwise its identity map serves stale rows across requests. The bundle's own repositories resolve the manager per call, reset a closed one by name and read the override map with array hydration.
- In production, prefer `missing_translation_log.async_persist: true` with an async transport so no DB write happens in `kernel.terminate` of the HTTP worker.
- Set `record_request_context: false` if request paths must not be stored (privacy); this does not affect worker safety.
- Do not use the machine-translation services (`MachineTranslatorInterface`, `ThrottledMachineTranslator`) from HTTP controllers; they are designed for CLI runs.
- A custom `web_ui.security.access_checker` must stay stateless (or implement `ResetInterface`).

## Re-audit triggers

Re-run this audit when a change adds: new properties to `RecordingTranslatorDecorator` or the recorder; a catalogue or "already recorded" cache that spans requests; a flush point other than `kernel.terminate`; new HTTP-facing services that use the machine translators; ORM writes in `persistBuffer()`; or any use of `$_SERVER` / `$_ENV` at runtime.
