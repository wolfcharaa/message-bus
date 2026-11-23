---
doc_id: guides.testing-durable-messages
contract_revision: 1
---

# Testing durable messages

The in-memory adapter provides deterministic acceptance and worker assertions but intentionally loses all state on process exit.

- Assert the Command receipt before manual `runOnce()` and terminal status afterwards.

- `TestInline` is only an explicit test route and has a distinct receipt outcome.

- Verify PostgreSQL transaction, CAS, and process guarantees in integration/process suites, not with the double.

Exact signatures are listed in the [public API](../reference/public-api.md); if they conflict, the source manifest is normative.
