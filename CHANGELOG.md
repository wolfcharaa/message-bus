# Changelog

## 6.1.0 - 2026-09-14

### Added

- Binding-specific `Envelope`/`MessageContextInterface` is now guaranteed for each built-in sequential middleware/handler invocation, including multi-binding sync fan-out.
- `FlowContract`, `FlowContractRegistry`, `MiddlewareRoleRegistry` and `FlowContractValidationRule` for semantic middleware role/order checks, stable `bindingId` requirements, binding owner requirements and required binding policy registries.
- Generic `RegistryOwner`, `RegistrySource` and `BindingRegistrationContext` metadata for compiled handler bindings.
- Optional `Wolfcharaa\MessageBus\Idempotency` contracts: idempotency key, intent fingerprint, execution metadata, claim/completion/effect value objects, typed store decisions, provider/resolved policy registries and `RequiresIdempotencyKey` middleware.

### Changed

- Handler attributes can carry optional `ownerKind`, `ownerId` and source diagnostics metadata.
- Compiled binding snapshots remain backward-compatible when owner/source metadata is absent.

## 6.0.0 - 2026-09-10

v6 makes command/query semantics explicit and removes the temporary v5.2 migration APIs.

### Breaking changes

- Registry schema version is bumped to `6`.
- Default registry compilation version is `6.0.0`.
- `CommandHandler` methods must return `void`.
- `QueryHandler` methods must declare a non-void return type and remain single-handler sync bindings.
- A message cannot have both a sync `QueryHandler` and a primary sync `CommandHandler`.
- `DomainHandler` and handler role metadata are removed.
- Legacy `Middleware\PipelineInterface` and the deprecation diagnostics mode are removed.
- `registry:compile` bootstrap files must return `RegistryCompileInput` or `RegistryCompilationResult`.

### Added

- `contextAware: false` on `CommandHandler`, `QueryHandler` and `EventSubscriber` for contextless invocation.
- `MessageContextInterface::dispatch()` now mirrors the generic PHPDoc template from `MessageBusInterface`.
- Registry diagnostics now report message kind conflicts and include more graph metadata in `registry:compile --explain`.

### Changed

- `dispatch()` remains the single sync API: query dispatch returns the query result; command dispatch executes the primary command and returns `void`.
- Built-in pipeline implementation moved to `Interceptor\Pipeline`.
- Command marker interface no longer carries a result template.

### Migration notes

See `docs/migration/v5.2-to-v6.md`.

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
