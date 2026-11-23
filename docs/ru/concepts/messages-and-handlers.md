---
doc_id: concepts.messages-and-handlers
contract_revision: 1
---

# Messages и handlers

`Message<TResult>` имеет четыре точные роли: Query и Request возвращают результат сразу, Command возвращает acceptance receipt, Event — aggregate publication receipt.

- `#[Handler]` ставится на public method; первый параметр — конкретный message, второй опционально — concrete `MessageContext`.

- Query/Request/Command требуют ровно один handler; Event допускает ноль или несколько stable subscription IDs.

- Command и durable Event обязаны иметь `#[MessageName]` и versioned `#[Payload]`.

- Runtime не угадывает роль по имени класса или attribute handler-а.

Точные signatures перечислены в [публичном API](../reference/public-api.md); при конфликте source manifest является нормативным.
