# Roadmap

Follow-ups that are planned but not scheduled for a release yet.

## Concurrency

- **Stale-form detection on the overrides desk** (accepted risk in 1.19, see [CONCURRENCY.md](CONCURRENCY.md#residual-risks)).
  Today two editors saving the same message both succeed and the later save wins. Plan: the edit form carries a hidden
  hash of the values it loaded; the save compares it with the stored values inside its write unit and, when the row
  changed meanwhile, answers a domain 409 with its own error code (not `CONFLICT_*`) and shows the current texts.
