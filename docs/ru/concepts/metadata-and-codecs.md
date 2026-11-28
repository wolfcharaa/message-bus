---
doc_id: concepts.metadata-and-codecs
contract_revision: 2
---

# Metadata и codecs

Message metadata отделена от payload: library владеет identity/causality/timestamps, application — только заранее объявленными portable fields.

- Metadata schema отклоняет неизвестные, nested и непереносимые значения.

- Durable payload хранит canonical message name, version, codec ID и exact bytes.

- Upcaster строит непрерывный путь от сохранённой версии к текущей; пропуск версии блокирует decode.

- `php-native` требует source-derived allowlist graph и совпадающий fingerprint.

- `MessageCodecs::fromRegistry($registry)` создаёт стандартные пары
  encoder/decoder `json` и `php-native` для каждого скомпилированного durable
  message. Передавайте mapping concrete property paths вторым аргументом только
  если native graph содержит interface или abstract type.

- Custom codec ID и историческая цепочка upcast остаются у application: в этом
  случае собирайте `MessageCodecs` явно. Автоматическая factory завершается до
  запуска runtime и указывает message class, wire name, version и
  неподдерживаемый codec, не выбирая скрытый fallback.

Точные signatures перечислены в [публичном API](../reference/public-api.md); при конфликте source manifest является нормативным.
