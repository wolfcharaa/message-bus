---
doc_id: concepts.delivery-and-attempts
contract_revision: 1
---

# Deliveries and attempts

One logical Message creates an independent Delivery per durable target; every attempt receives its own AttemptId and lease token.

- Acceptance atomically stores the canonical message and its target delivery.

- Claiming uses `FOR UPDATE SKIP LOCKED`, database time, and fenced compare-and-set transitions.

- A late worker cannot settle a newer attempt with an old lease token.

- `outcome_unknown` means an external effect may exist without proven settlement; it is terminal, not a normal retry.

Exact signatures are listed in the [public API](../reference/public-api.md); if they conflict, the source manifest is normative.
