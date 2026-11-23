---
doc_id: guides.codecs-and-upcasters
contract_revision: 1
---

# Codecs и upcasters

Codec отвечает за exact bytes одной версии; upcasters преобразуют decoded data по одному version step.

- Registry `MessageCodecs` разделяет encoder текущего class и decoder по name/version.

- Старую версию нельзя молча интерпретировать новым constructor contract.

- Перед удалением upcaster докажите отсутствие соответствующих retained records.

Точные signatures перечислены в [публичном API](../reference/public-api.md); при конфликте source manifest является нормативным.
