---
doc_id: operations.worker-profiles
contract_revision: 1
---

# Worker profiles

One immutable profile owns one lane, concurrency, and time bounds; arbitrary handler filters are unsupported.

- Heartbeat interval must be materially shorter than the lease and fit the storage-outage budget.

- The child runtime factory creates fresh process-local connections after fork.

- External Docker/systemd grace must exceed the internal drain and kill window.

Exact signatures are listed in the [public API](../reference/public-api.md); if they conflict, the source manifest is normative.
