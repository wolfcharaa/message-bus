---
doc_id: getting-started.durable-postgres
contract_revision: 2
---

# Durable PostgreSQL delivery

This path adds Command, durable Event, PostgreSQL storage, a PCNTL supervisor, and operator control to the same registry contract.

- Apply immutable SQL artifacts as a separate deploy step and run `schema:validate` before producers or workers start.

- A Command succeeds at `accepted` or `staged`; that does not mean its handler has completed.

- Workers use a dedicated runtime connection without a foreign transaction; children create process-local resources after fork.

- Prefer `PostgresRuntime::create()` for the package-owned wiring. It returns
  the bus, worker, backend, schema manager, delivery operations/maintenance,
  idempotency maintenance, and worker control built around one PDO and one
  registry. Standard codecs are derived automatically:

```php
$runtime = PostgresRuntime::create(
    pdo: $processLocalPdo,
    registry: $registry,
    handlers: $handlerResolver,
    routes: $routes,
    policies: $policies,
    schema: 'application',
);

$receipt = $runtime->bus->dispatch($command);
$iteration = $runtime->worker->runOnce($profile, $workerId);
```

  The host still creates and closes PDO, applies migrations as a deploy step,
  chooses routes/policies, and creates a fresh runtime after each fork. Never
  pass an inherited parent PDO into `create()` in a child.

- Run `composer example:durable` against disposable PostgreSQL; the in-memory backend is not persistence evidence.

Exact signatures are listed in the [public API](../reference/public-api.md); if they conflict, the source manifest is normative.
