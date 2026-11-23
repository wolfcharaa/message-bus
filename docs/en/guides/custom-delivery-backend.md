---
doc_id: guides.custom-delivery-backend
contract_revision: 1
---

# Custom durable backend

`DurableDeliveryBackend` is a complete port, not an enqueue/dequeue abstraction.

- An adapter must implement atomic acceptance, fenced claim/heartbeat/settlement, cancel, recovery, replay, and bounded pruning.

- State and attempt history plus race outcomes must match the PostgreSQL adapter.

- Claim production support only after package conformance and crash/race tests pass.

Exact signatures are listed in the [public API](../reference/public-api.md); if they conflict, the source manifest is normative.
