# Concurrency (REQ-RUNTIME-003)

How the bundle's database writes stay correct when the same data is written at the same time: two editors saving the
same text, two workers logging the same missing key, a retried request or a redelivered Messenger message. Since 1.19
every write path has a transaction, a concurrency control and a defined answer to a lock conflict. Worker-mode state
(what survives between requests) is covered in [FRANKENPHP-WORKER-AUDIT.md](FRANKENPHP-WORKER-AUDIT.md).

The YAML console tools (`tree`, `sort`, `fill-missing`, `key`, …) write files, not the database, and are out of scope.

## Table of contents

- [Write paths](#write-paths)
- [Conflict answer and codes](#conflict-answer-and-codes)
- [Host options](#host-options)
- [Entity managers](#entity-managers)
- [Tests](#tests)
- [Residual risks](#residual-risks)

## Write paths

"Unit" is the bundle's write unit (`TranslationYamlToolsWriteUnit`, transaction-doctrine's runner): one transaction (a
savepoint inside a transaction you opened), the manager flushed before the commit, the connection's nesting level
repaired after a failure, and a deadlock, lock wait timeout or serialization failure rethrown as
`Nowo\TransactionContract\TransientStorageFailure` (the DBAL error as previous).

| Path | Transaction | Control (rule 4) | Conflict answer |
| --- | --- | --- | --- |
| Overrides desk: save a message (`TranslationOverrideEditor::save()`, every locale) | One unit for all locales; the cached map is dropped after the commit | Unique key (locale, domain, key). Two first saves at once: the loser's insert fails with a unique violation and its unit runs once more, finding the winner's row and updating it (last save wins, as for any two saves of an existing row). The second run happens only when the bundle's own manager (private or dedicated) had nothing pending before the unit; with rows staged earlier with `flush: false`, or on the default manager (deprecated modes), the violation is rethrown: the failed unit discarded that work, so a second run would report success without it | 409 page (HttpException) |
| Overrides desk: restore a message (`TranslationOverrideEditor::reset()`) | One unit for all locales | Deletes by key; deleting a row another request already deleted writes nothing | 409 page |
| `TranslationOverrides::set()` / `flush()`, the form bridge (`save(..., flush: false)` then `flush()`) | One unit per call with `flush: true`; staged rows are written by one unit in `flush()` | As above; a unique violation in a bare `flush()` (rows staged earlier) is rethrown, since the staged rows cannot be read again | Your route: rethrown to your code |
| Missing log: buffer at `kernel.terminate` / `console.terminate` / after a Messenger message (`DoctrineMissingTranslationRecorder`) | One transaction per 200 keys (`MissingTranslationLogRepository::PERSIST_CHUNK`), keys in a fixed (locale, domain, message) order | Atomic upsert per key (`INSERT … ON DUPLICATE KEY UPDATE hit_count = hit_count + n` on MySQL / MariaDB, `ON CONFLICT … DO UPDATE` on PostgreSQL and SQLite): never read-modify-write, never a duplicate row. A deadlock or lock wait timeout replays the chunk in place (3 attempts, a short random pause) | None (after the response); a failure that outlives the replays is logged at error level and the buffer dropped |
| Missing log with `async_persist` (Messenger handler, event listener) | Same as above | Same as above | Handler: rethrown after the replays, so Messenger retries the message |
| Missing log UI: mark added (`MissingTranslationLogUiController::markAdded`), `missing-log:mark-added`, `missing-log:validate` | One unit (the ORM writes only the changed status columns) | Status change only: a concurrent upsert of the same key (hit count, last seen) is kept; two editors marking the same row both succeed | 409 page / console: rethrown |
| Missing log UI: clear all / clear one status | One unit (one `DELETE`) | Idempotent | 409 page |
| Deprecated flush bridge (`PrivateEntityManagerFlushBridge`, rows staged with `flush: false` written by your flush) | The private manager's flush after your commit | — | Logged, never rethrown (your commit is done; see [Residual risks](#residual-risks)) |

`grep -rn "flush()" src/` hits are the units above (`TranslationYamlToolsWriteUnit`), the repository and service
`flush()` methods that call them, and the deprecated bridge.

## Conflict answer and codes

A lock conflict, deadlock, lock wait timeout or serialization failure (MySQL / MariaDB 1213, 1205, 1020; PostgreSQL
40001, 40P01, 55P03) on one of the bundle's Web UI routes answers:

| Code | When | Status | Headers |
| --- | --- | --- | --- |
| `CONFLICT_001` | Nothing was committed: retrying is safe | 409 (or 503) | `X-Conflict-Code: CONFLICT_001`, `Retry-After` |
| `CONFLICT_002` | Part of the request was committed (transaction-doctrine's request commit mark, a committed `ConcurrencyConflict`, a Messenger handler that already completed, a lost savepoint): read again, do not repeat | 409 (or 503) | `X-Conflict-Code: CONFLICT_002`, no `Retry-After` |

- The pages get an `HttpException` with that status and those headers, rendered by your error page, with a generic
  translated message (`conflict.retry` / `conflict.committed` in the `NowoTranslationYamlToolsBundle` domain).
- Not conflicts, never mapped: unique (and other constraint) violations and lost connections. They stay real errors.
  The one expected unique race (two first saves of a message) is resolved inside the unit, as described above.
- Host routes are never answered: a lock wait inside `TranslationOverrides::set()` called from your code is rethrown to
  you (rule 8: never swallowed).
- "Part committed" needs transaction-doctrine's commit detection (its bundle, `commit_detection: true`) to see commits
  made earlier in the same request by your code or another bundle; the bundle does not wrap your connections itself.
  Without it the failure alone decides. A bundle unit commits only when it succeeds, so a failed save on its own is
  always `CONFLICT_001`.

The listener is `TranslationYamlToolsConflictListener` (`kernel.exception`, priority -64: after your own exception
listeners, before the framework's error listener). It is registered only when a Web UI is enabled.

## Host options

```yaml
nowo_translation_yaml_tools:
    conflict_response:
        status: 409        # or 503 for hosts that keep a Service Unavailable answer
        retry_after: 1     # seconds, CONFLICT_001 only
```

Set a lock timeout on your connection so a write never waits without limit: MySQL / MariaDB
`innodb_lock_wait_timeout` (default 50 seconds), PostgreSQL `lock_timeout` (default 0, no limit), for example with a
DBAL middleware or the connection's `options`. The bundle routes are same-origin; a cross-origin client that reads the
code must list `X-Conflict-Code` in `Access-Control-Expose-Headers`.

## Entity managers

- **Private manager** (`doctrine.entity_manager: null`, the default since 1.18): the unit uses transaction-doctrine's
  `forEntityManager()` on the bundle's private manager; after a storage failure it is cleared, and a closed one is
  replaced at its next use. Your managers are never flushed, cleared or reset.
- **Dedicated manager** (`doctrine.entity_manager: translations`): `forEntityManager()` with the registry; after a
  storage failure that manager (only) is closed and reset by name.
- **The default manager** (the deprecated `doctrine.private_entity_manager: false`, or `entity_manager` naming the
  default): the DBAL runner (`forConnection()`) and the unit flushes the manager itself, as those deprecated modes always
  did (your pending changes are written with the bundle's rows). An open manager is never cleared or reset; one a failed
  flush closed is replaced through the registry. `forHostEntityManager()` is not used: in those modes rows staged with
  `flush: false` are pending on that manager on purpose, and it refuses pending changes.
- Worker mode with kernel reset false: nothing of a unit is kept on a service; the private manager is dropped at every
  request / message / command boundary. A closed manager of yours is not replaced by the bundle; it is replaced only if
  you run transaction-doctrine's worker hygiene (`WorkerConnectionHygiene`) or your own.

## Tests

- `tests/Integration/Concurrency/ConcurrencyRaceTest.php` (`--group database`; CI job "Databases on …" on MySQL 8.4,
  MariaDB 11.4 and PostgreSQL 16, DBAL 4 and DBAL 3, required): two real connections, lock waits capped at one second.
  - A real lock wait on the desk: 409, `CONFLICT_001`, `Retry-After`; nothing written; the retry saves; the connection
    is usable.
  - Two first saves of a message: one row, the last save wins; the unit ran twice with the manager replaced in between.
  - The same race after a row was staged with `flush: false`: the unique violation is rethrown, never a silent loss.
  - The same lock failure after an earlier commit of the request: `CONFLICT_002`, no `Retry-After`.
  - Two buffers upserting the same missing key: one row, no hit lost or counted twice (the failed attempts rolled back).
  - "Mark added" racing an upsert: both the status and the new hits are kept.
- `tests/Unit/Concurrency`, `tests/Unit/EventListener/TranslationYamlToolsConflictListenerTest.php`: the unit per
  manager kind (private, dedicated, default, unregistered), in-place replays (and none inside your transaction), the
  mapping (001 / 002, 409 / 503, unique violations, lost connections and host routes left alone).

## Residual risks

- **Hit counts on redelivery.** A Messenger message handled, committed and then redelivered (the ack lost) adds its
  hits twice. The hit count is a diagnostic counter; the row itself is never duplicated.
- **Messenger retry after a partial buffer.** With `async_persist` and Messenger, when a later chunk of one message
  still fails after its replays, the handler rethrows and Messenger retries the whole message: the hits of the chunks
  already committed are added again (diagnostics only, no duplicate row).
- **Missing-log buffer dropped after the replays.** At `kernel.terminate` nothing can roll back or answer any more: a
  chunk that still fails after three attempts is logged at error level and dropped (diagnostic data only). Use
  `async_persist` with Messenger to have it retried.
- **Cache inside your transaction.** When you call `TranslationOverrides::set()` / `flush()` inside a transaction you
  opened, the cached map is dropped when the bundle's unit ends, before your commit. Another request may cache the old
  texts in between, until `overrides.cache_ttl`. Call `TranslationOverrides::invalidate()` after your commit.
- **Deprecated flush bridge.** Rows staged with `flush: false` and written by your own flush (deprecated since 1.18,
  removed in 2.0) are written after your commit; a failure there is logged, never rethrown. Call
  `TranslationOverrideEditor::flush()` instead.
- **Stale form, last writer wins** (accepted for 1.19). Two editors saving the same message both succeed; the later
  save is kept (both carry `updated_by`). Planned follow-up in [ROADMAP.md](ROADMAP.md): a hidden hash of the loaded
  values and a domain 409 when the row changed meanwhile.
- **First-save race with staged rows.** Losing the first-save race after rows were staged with `flush: false` (or on
  the default manager) is rethrown, not resolved: the caller retries the whole form.
