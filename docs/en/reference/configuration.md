---
doc_id: reference.configuration
contract_revision: 3
---

# Configuration reference

The library reads no environment variables. The host explicitly composes registry, service map, routes, context factories, middleware, and an optional durable runtime in PHP.

- Orchestration-only requires registry, handlers, and routes.

- Durable adds a backend, codecs, policies, worker profile/control, and observer as required.

- Handler execution composes global middleware objects, durable-policy class
  references, and classes compiled from `#[Handler(middleware: [...])]` in that
  order. Referenced classes resolve through the handler application scope.

- An unknown handler, context factory, route, codec, or policy blocks construction or bootstrap.

- For a quick start, `ComposerDirectoryClassSource::fromComposerJson()` reads
  only root production `psr-4`, `classmap`, and `exclude-from-classmap`.
  Setup and the required explicit path are documented in
  [Composer discovery](../getting-started/composer-directory-discovery.md).

- The host explicitly selects `RegistryRefreshPolicy`: `PrecompiledOnly` for
  production without a source and `WhenStale` for development with a
  refreshable source. The library does not read `APP_ENV` or rebuild inside
  dispatch.

Exact signatures are listed in the [public API](../reference/public-api.md); if they conflict, the source manifest is normative.
