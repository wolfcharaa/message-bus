---
doc_id: operations.status-cancel-replay
contract_revision: 2
---

# Status, cancel, and replay

Applications use opaque DeliveryId and public operations instead of reading internal tables directly.

- Cancel returns a race outcome: before-start, requested-running, already-requested, already-terminal, or not-found.

- Replay always creates a new DeliveryId and retains a link to the original history.

- Replaying `outcome_unknown` requires an idempotent policy and must remain inside the original delivery’s persisted replay window; a new policy does not widen the old safety horizon.

- Every mutation requires an authorized actor and reason; the library does not perform authorization.

Exact signatures are listed in the [public API](../reference/public-api.md); if they conflict, the source manifest is normative.
