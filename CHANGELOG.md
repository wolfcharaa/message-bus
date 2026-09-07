# Changelog

## 5.2.0 - 2026-09-07

v5.2 adds compile-time registry diagnostics and contextless domain capability handlers without changing the PostgreSQL schema.

### Added

- `DomainHandler` for synchronous domain capability scenarios with `__invoke(Message $message): Result` signatures.
- Explicit handler role and invocation mode metadata in compiled bindings.
- `MessageRegistryCompiler::compileWithDiagnostics()` with typed diagnostics and partial graph context.
- `RegistryValidationRuleInterface` for project-owned architecture checks.
- `registry:compile` support for `RegistryCompileInput`, readable diagnostics, `--explain`, `--base-path`, `--fail-on-warning` and `--deprecations=ignore|warn|fail`.
- `InterceptorInterface` and `Interceptor\PipelineInterface` as the preferred extension contracts.

### Changed

- Default compiled registry library version is `5.2.0`.
- `QueryHandler` is extensible so `DomainHandler` can reuse query-like routing semantics.
- Legacy `Middleware` API usage can emit opt-in warning or error diagnostics.
- Nested PostgreSQL retry execution joins an existing transaction instead of opening another root transaction.

### Compatibility

- Existing exception-based `discover()` and `compile()` APIs remain available.
- Existing `registry:compile` bootstrap files returning `MessageRegistryDefinition` remain supported.
- Compiled bindings without role/invocation fields load as application, context-aware handlers.
- Legacy `Middleware\PipelineInterface` remains accepted during the v5 migration period.
- No database migration is required from v5.1.

### Migration notes

See `docs/migration/v5.1-to-v5.2.md`.

## 5.0.0

v5 is a new major version. Runtime behavior, registry schema and PostgreSQL schema are not required to stay compatible with v4.

### Breaking changes

- Registry schema version is bumped to `5`.
- Default registry compilation version is `5.0.0`.
- v4 compiled registry cache must be rebuilt.
- PSR-11 container is the required service integration contract.
- Queue job control contract now includes `heartbeat()` and `isCancellationRequested()`.
- Message context factory receives optional `WorkerRuntimeControlInterface`.
- Built-in PostgreSQL schema should be deployed as fresh v5 schema.

### Added

- PostgreSQL async queue runtime.
- Queue status polling APIs.
- Queue cancellation and cooperative cancellation support.
- PHP serialize payload serializer support for PHP-only projects.
- Custom payload serializer path for protobuf/binary payloads.
- Worker control plane with pause/resume/drain/stop/kill/restart/status.
- `pcntl` auto worker mode with master/child processes.
- Worker and child lifecycle registry.
- Handler context heartbeat and cancellation contracts.

### Fixed during release stabilization

- PostgreSQL worker-control boolean parameter binding now writes explicit `true`/`false` values.
- PostgreSQL worker-control integration test no longer depends on a fixed expiration timestamp.

### Migration notes

See `README.md` section `Migration from v4 to v5`.
