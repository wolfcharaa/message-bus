---
doc_id: getting-started.orchestration
contract_revision: 1
---

# Оркестрация без очереди

Минимальный путь для Query, Request и inline Event: скомпилируйте реестр, передайте готовые handler services и вызывайте единый `dispatch()`.

- Каждый message реализует ровно одну роль; Query/Request имеют ровно один handler.

- Реестр компилируется при сборке, а production bootstrap только загружает и проверяет artifact.

- Inline handler выполняется в вызывающем процессе; исключение возвращается вызывающему коду.

- Начните с `examples/orchestration/run.php` и не подключайте PostgreSQL/worker без durable-сценария.

Точные signatures перечислены в [публичном API](../reference/public-api.md); при конфликте source manifest является нормативным.
