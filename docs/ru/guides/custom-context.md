---
doc_id: guides.custom-context
contract_revision: 1
---

# Custom MessageContext

Создайте interface, расширяющий `MessageContext`, и зарегистрируйте exact `MessageContextFactory` для этого type.

- Factory получает только `MessageContextSeed`: dispatcher, immutable metadata и cancellation signal.

- Compiler связывает context type с handler method; missing factory блокирует composition.

- Не помещайте PDO, container или непереносимые application objects в persisted metadata.

Точные signatures перечислены в [публичном API](../reference/public-api.md); при конфликте source manifest является нормативным.
