---
doc_id: guides.testing-durable-messages
contract_revision: 1
---

# Тестирование durable messages

In-memory adapter даёт deterministic acceptance/worker assertions, но намеренно теряет данные при завершении процесса.

- Проверяйте Command receipt до ручного `runOnce()` и terminal status после него.

- `TestInline` разрешён только как явный test route и имеет отдельный receipt outcome.

- PostgreSQL transaction, CAS и process guarantees проверяйте integration/process suites, не double-ом.

Точные signatures перечислены в [публичном API](../reference/public-api.md); при конфликте source manifest является нормативным.
