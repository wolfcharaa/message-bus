---
doc_id: getting-started.orchestration
contract_revision: 2
---

# Orchestration without a queue

The shortest path for Query, Request, and inline Event: compile a registry, provide resolved handler services, and call the single `dispatch()` method.

- Every message implements exactly one role; Query/Request have exactly one handler.

- Compile the registry during the build; production bootstrap only loads and verifies the artifact.

- An inline handler runs in the caller process and propagates failure to the caller.

- Start with `examples/orchestration/run.php`; do not add PostgreSQL or a worker without a durable use case.

- If compilation or dispatch fails, do not replace the original exception;
  follow the [diagnostic troubleshooting guide](../guides/troubleshooting-diagnostics.md).

Exact signatures are listed in the [public API](../reference/public-api.md); if they conflict, the source manifest is normative.
