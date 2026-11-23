---
doc_id: reference.configuration
contract_revision: 1
---

# Configuration reference

The library reads no environment variables. The host explicitly composes registry, service map, routes, context factories, middleware, and an optional durable runtime in PHP.

- Orchestration-only requires registry, handlers, and routes.

- Durable adds a backend, codecs, policies, worker profile/control, and observer as required.

- An unknown handler, context factory, route, codec, or policy blocks construction or bootstrap.

Exact signatures are listed in the [public API](../reference/public-api.md); if they conflict, the source manifest is normative.
