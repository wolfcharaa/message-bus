---
doc_id: guides.metadata-enrichment
contract_revision: 1
---

# Metadata enrichment

Объявите allowlist key→scalar/null в `MetadataSchema`, затем возвращайте значения из `MetadataEnricher`.

- Enricher вызывается до dispatch и не изменяет library-owned IDs/timestamps.

- Secrets, arbitrary headers и nested objects отклоняются.

- Добавление persisted key — wire-contract change и требует migration consideration.

Точные signatures перечислены в [публичном API](../reference/public-api.md); при конфликте source manifest является нормативным.
