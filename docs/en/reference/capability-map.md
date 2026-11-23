---
doc_id: reference.capability-map
contract_revision: 1
---

# Capability map

This map connects a consumer task to its public boundary and owner.

- Sync orchestration → `MessageBus`, compiler/artifact; the host owns DI and handler services.

- Durable PostgreSQL → backend/schema/worker/control; the host owns credentials, deployment, and process supervision.

- Extension → only `@api spi`; a custom adapter never imports `Internal`.

- Testing → in-memory semantics plus PostgreSQL/process suites for persistence and fork guarantees.

Exact signatures are listed in the [public API](../reference/public-api.md); if they conflict, the source manifest is normative.
