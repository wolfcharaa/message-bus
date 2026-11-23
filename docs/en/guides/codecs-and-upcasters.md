---
doc_id: guides.codecs-and-upcasters
contract_revision: 1
---

# Codecs and upcasters

A codec owns exact bytes for one version; upcasters transform decoded data one version step at a time.

- `MessageCodecs` separates the current-class encoder from decoders keyed by name and version.

- Never silently interpret old bytes with a new constructor contract.

- Before removing an upcaster, prove that no retained record requires it.

Exact signatures are listed in the [public API](../reference/public-api.md); if they conflict, the source manifest is normative.
