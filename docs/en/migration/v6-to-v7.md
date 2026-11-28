---
doc_id: migration.v6-to-v7
contract_revision: 2
---

# Migrating v6 to v7

V7 is an intentional breaking redesign with no shims for Envelope, Flow, execution strategies, queue rows, or worker internals.

- First classify every message as Query, Request, Command, or Event and move handlers to method-level `#[Handler]`.

- Replace runtime discovery with a compiled artifact and all dispatch/publish variants with the single `dispatch()` method.

- To migrate without a manual class list, enable
  [Composer directory discovery](../getting-started/composer-directory-discovery.md),
  clear `MBR021`–`MBR028` during build, and use `PrecompiledOnly` in production.

- Perform durable cutover offline: stop producers, drain workers, prove zero running, dry-run the importer, apply v7 schema, import, backfill, and verify.

- After the first v7 acceptance, recovery is forward-only; legacy tables remain read-only until a separate application migration removes them.

Exact signatures are listed in the [public API](../reference/public-api.md); if they conflict, the source manifest is normative.
