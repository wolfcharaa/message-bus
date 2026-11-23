---
doc_id: guides.metadata-enrichment
contract_revision: 1
---

# Metadata enrichment

Declare the key-to-scalar/null allowlist in `MetadataSchema`, then return values from `MetadataEnricher`.

- The enricher runs before dispatch and cannot replace library-owned IDs or timestamps.

- Secrets, arbitrary headers, and nested objects are rejected.

- Adding a persisted key changes the wire contract and requires migration consideration.

Exact signatures are listed in the [public API](../reference/public-api.md); if they conflict, the source manifest is normative.
