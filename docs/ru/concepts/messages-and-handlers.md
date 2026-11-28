---
doc_id: concepts.messages-and-handlers
contract_revision: 4
---

# Messages и handlers

`Message<TResult>` имеет четыре точные роли: Query и Request возвращают результат сразу, Command возвращает acceptance receipt, Event — aggregate publication receipt.

- `#[Handler]` ставится на public method; первый параметр — конкретный message, второй опционально — concrete `MessageContext`.

- Query/Request/Command требуют ровно один handler; Event допускает ноль или несколько stable subscription IDs.

- `#[Handler(bindingId: 'command.name')]` задаёт Command стабильный route и
  persisted target ID, не зависящий от PHP class. Если параметр не указан, для
  обратной совместимости остаётся имя класса. Идентичность Event по-прежнему
  задаётся через `#[Handler(subscriptionId: '...')]`; Query и Request не
  принимают durable identity. Код `MBR029` точно указывает identity у неверной
  роли.

- Command и durable Event обязаны иметь `#[MessageName]` и versioned `#[Payload]`.

- `#[Handler(middleware: [...])]` объявляет middleware classes только для этого
  binding. Compiler сохраняет их порядок в registry artifact; `MBR030` точно
  указывает неверный элемент. Global и policy composition описаны в
  [руководстве по handler middleware](../guides/handler-middleware.md).

- Runtime не угадывает роль по имени класса или attribute handler-а.

- Ошибки handler resolver/DI-контейнера не отбрасываются. Query и Request
  пробрасывают их напрямую; aggregate-ошибка Event сохраняет все исходные
  исключения в `PublicationFailed::$failures`, а первое — также как `previous`.
  Durable worker сначала завершает текущую attempt, затем повторно бросает
  ошибку resolution; PCNTL child выводит её перед завершением.

Точные signatures перечислены в [публичном API](../reference/public-api.md); при конфликте source manifest является нормативным.
