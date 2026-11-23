---
doc_id: getting-started.durable-postgres
contract_revision: 1
---

# Durable PostgreSQL delivery

This path adds Command, durable Event, PostgreSQL storage, a PCNTL supervisor, and operator control to the same registry contract.

- Apply immutable SQL artifacts as a separate deploy step and run `schema:validate` before producers or workers start.

- A Command succeeds at `accepted` or `staged`; that does not mean its handler has completed.

- Workers use a dedicated runtime connection without a foreign transaction; children create process-local resources after fork.

- Run `composer example:durable` against disposable PostgreSQL; the in-memory backend is not persistence evidence.

Exact signatures are listed in the [public API](../reference/public-api.md); if they conflict, the source manifest is normative.
