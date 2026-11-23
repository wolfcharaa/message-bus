---
doc_id: concepts.metadata-and-codecs
contract_revision: 1
---

# Metadata and codecs

Message metadata is separate from payload: the library owns identity, causality, and timestamps; the application owns only declared portable fields.

- The metadata schema rejects unknown, nested, and non-portable values.

- A durable payload stores canonical message name, version, codec ID, and exact bytes.

- Upcasters form a continuous path from the stored version to the current version; a missing step blocks decoding.

- `php-native` requires a source-derived allowlist graph with a matching fingerprint.

Exact signatures are listed in the [public API](../reference/public-api.md); if they conflict, the source manifest is normative.
