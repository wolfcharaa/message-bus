---
doc_id: reference.capability-map
contract_revision: 3
---

# Capability map

This map connects a consumer task to its public boundary and owner.

- Sync orchestration → `MessageBus`, compiler/artifact; the host owns DI and handler services.

- Durable PostgreSQL → backend/schema/worker/control; the host owns credentials, deployment, and process supervision.

- Extension → only `@api spi`; a custom adapter never imports `Internal`.

- Testing → in-memory semantics plus PostgreSQL/process suites for persistence and fork guarantees.

- Quick handler setup → [Composer directory discovery](../getting-started/composer-directory-discovery.md);
  the host owns roots, the environment flag, artifact path, and worker restart.

- Diagnostics → the [troubleshooting guide](../guides/troubleshooting-diagnostics.md)
  owns the response sequence, while the [reference](diagnostics.md) defines the
  exact meaning and correction for every `MBR`, `MBC`, `MBD`, and `MBCLI`.

Exact signatures are listed in the [public API](../reference/public-api.md); if they conflict, the source manifest is normative.
