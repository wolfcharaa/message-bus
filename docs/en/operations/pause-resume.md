---
doc_id: operations.pause-resume
contract_revision: 2
---

# Pause and resume

Control state is revisioned and applies globally or to one exact profile; it stops new claims but does not kill attempts already running.

- A pause/resume mutation has an idempotency key and optional expected revision.

- `worker:pause --wait` waits for effective paused state, applied global/profile revisions, and zero authoritative active attempts; timeout is a non-zero outcome.

- `worker:status` derives required/applied revisions, idle/busy, stale, and drained without mutating rows. Mutually exclusive `--profile` and `--instance` filters are available.

- Starting and stopping processes remains the external supervisor’s responsibility.

Exact signatures are listed in the [public API](../reference/public-api.md); if they conflict, the source manifest is normative.
