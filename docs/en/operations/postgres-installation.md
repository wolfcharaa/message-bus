---
doc_id: operations.postgres-installation
contract_revision: 1
---

# Installing the PostgreSQL schema

The host applies ordered SQL artifacts from `resources/migrations`; producers and workers never execute DDL at startup.

- Use a deploy role with CREATE/ALTER privileges and runtime roles with only required DML.

- The manifest records component, immutable migration ID, checksum, and installation fingerprint.

- `schema:validate` must pass before rollout; drift is never repaired automatically.

Exact signatures are listed in the [public API](../reference/public-api.md); if they conflict, the source manifest is normative.
