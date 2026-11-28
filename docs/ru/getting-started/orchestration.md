---
doc_id: getting-started.orchestration
contract_revision: 3
---

# Оркестрация без очереди

Минимальный путь для Query, Request и inline Event: скомпилируйте реестр, передайте готовые handler services и вызывайте единый `dispatch()`.

- Каждый message реализует ровно одну роль; Query/Request имеют ровно один handler.

- Реестр компилируется при сборке, а production bootstrap только загружает и проверяет artifact.

- Inline handler выполняется в вызывающем процессе; исключение возвращается вызывающему коду.

- Начните с `examples/orchestration/run.php` и не подключайте PostgreSQL/worker без durable-сценария.

- В host с PSR-11 передавайте `new PsrContainerHandlerServiceResolver($container)`
  вместо копирования сервисов в `HandlerServices`. Adapter делегирует `has()` и
  `get()`: исключение container/autowiring выходит без замены, а успешно
  возвращённый несовместимый объект отклоняется с указанием запрошенного
  handler class и фактического типа.

- Если компиляция или dispatch завершились ошибкой, не заменяйте исходное
  исключение: следуйте [руководству по диагностике](../guides/troubleshooting-diagnostics.md).

Точные signatures перечислены в [публичном API](../reference/public-api.md); при конфликте source manifest является нормативным.
