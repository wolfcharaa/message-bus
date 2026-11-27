---
doc_id: concepts.messages-and-handlers
contract_revision: 2
---

# Messages и handlers

`Message<TResult>` имеет четыре точные роли: Query и Request возвращают результат сразу, Command возвращает acceptance receipt, Event — aggregate publication receipt.

- `#[Handler]` ставится на public method; первый параметр — конкретный message, второй опционально — concrete `MessageContext`.

- Query/Request/Command требуют ровно один handler; Event допускает ноль или несколько stable subscription IDs.

- Command и durable Event обязаны иметь `#[MessageName]` и versioned `#[Payload]`.

- Runtime не угадывает роль по имени класса или attribute handler-а.

- Ошибки handler resolver/DI-контейнера не отбрасываются. Query и Request
  пробрасывают их напрямую; aggregate-ошибка Event сохраняет все исходные
  исключения в `PublicationFailed::$failures`, а первое — также как `previous`.
  Durable worker сначала завершает текущую attempt, затем повторно бросает
  ошибку resolution; PCNTL child выводит её перед завершением.

Точные signatures перечислены в [публичном API](../reference/public-api.md); при конфликте source manifest является нормативным.
