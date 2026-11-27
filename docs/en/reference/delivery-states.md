---
doc_id: reference.delivery-states
contract_revision: 2
---

# Delivery states

The primary chain is `staged|accepted → running → succeeded|failed|cancelled|outcome_unknown`; retry uses `retry_wait → running`.

- `staged` is visible only inside an external application transaction until commit.

- `running` is always tied to a current AttemptId and lease token.

- Terminal states are immutable; replay creates a new delivery.

- Status retains the latest failure category, its AttemptId, and a safe `FailureDiagnostic` descriptor. The descriptor identifies code, stage, exception class, related class, and fingerprint without persisting an arbitrary exception message or trace.

Exact signatures are listed in the [public API](../reference/public-api.md); if they conflict, the source manifest is normative.
