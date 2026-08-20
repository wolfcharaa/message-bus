# Changelog

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
