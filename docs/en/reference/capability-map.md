---
doc_id: reference.capability-map
contract_revision: 5
---

# Capability map

This map connects a consumer task to its public boundary and owner.

- Sync orchestration → `MessageBus`, compiler/artifact; the host owns DI and handler services.

- Durable PostgreSQL → backend/schema/worker/control; the host owns credentials, deployment, and process supervision.

- Framework composition → PSR-11 handler resolver, registry-derived codecs,
  `PostgresRuntime`, and embeddable Symfony commands; the host still owns
  service definitions, PDO/process lifetime, routes, policies, and migration timing.

- Extension → only `@api spi`; a custom adapter never imports `Internal`.

- Handler interception → [handler middleware](../guides/handler-middleware.md);
  the host owns global objects and DI services, the binding owns local class
  references, and a durable policy owns its persisted attempt-level chain.

- Testing → in-memory semantics plus PostgreSQL/process suites for persistence and fork guarantees.

- Quick handler setup → [Composer directory discovery](../getting-started/composer-directory-discovery.md);
  the host owns roots, the environment flag, artifact path, and worker restart.

- Diagnostics → the [troubleshooting guide](../guides/troubleshooting-diagnostics.md)
  owns the response sequence, while the [reference](diagnostics.md) defines the
  exact meaning and correction for every `MBR`, `MBC`, `MBD`, and `MBCLI`.

Exact signatures are listed in the [public API](../reference/public-api.md); if they conflict, the source manifest is normative.
