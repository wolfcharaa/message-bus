---
doc_id: reference.delivery-states
contract_revision: 1
---

# Delivery states

The primary chain is `staged|accepted → running → succeeded|failed|cancelled|outcome_unknown`; retry uses `retry_wait → running`.

- `staged` is visible only inside an external application transaction until commit.

- `running` is always tied to a current AttemptId and lease token.

- Terminal states are immutable; replay creates a new delivery.

Exact signatures are listed in the [public API](../reference/public-api.md); if they conflict, the source manifest is normative.
