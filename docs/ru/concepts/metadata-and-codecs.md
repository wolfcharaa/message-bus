---
doc_id: concepts.metadata-and-codecs
contract_revision: 1
---

# Metadata и codecs

Message metadata отделена от payload: library владеет identity/causality/timestamps, application — только заранее объявленными portable fields.

- Metadata schema отклоняет неизвестные, nested и непереносимые значения.

- Durable payload хранит canonical message name, version, codec ID и exact bytes.

- Upcaster строит непрерывный путь от сохранённой версии к текущей; пропуск версии блокирует decode.

- `php-native` требует source-derived allowlist graph и совпадающий fingerprint.

Точные signatures перечислены в [публичном API](../reference/public-api.md); при конфликте source manifest является нормативным.
