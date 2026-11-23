---
doc_id: getting-started.durable-postgres
contract_revision: 1
---

# Durable delivery в PostgreSQL

Этот путь добавляет Command, durable Event, PostgreSQL storage, PCNTL supervisor и operator control к тому же registry contract.

- Примените immutable SQL artifacts отдельным deploy-шагом и выполните `schema:validate` до запуска producer/worker.

- Command считается успешным после `accepted` или `staged`, но не после выполнения handler.

- Worker использует отдельное runtime-соединение без чужой транзакции; child создаёт process-local resources после fork.

- Запустите `composer example:durable` на disposable PostgreSQL; in-memory backend не доказывает persistence.

Точные signatures перечислены в [публичном API](../reference/public-api.md); при конфликте source manifest является нормативным.
