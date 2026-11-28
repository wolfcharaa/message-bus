---
doc_id: getting-started.durable-postgres
contract_revision: 2
---

# Durable delivery в PostgreSQL

Этот путь добавляет Command, durable Event, PostgreSQL storage, PCNTL supervisor и operator control к тому же registry contract.

- Примените immutable SQL artifacts отдельным deploy-шагом и выполните `schema:validate` до запуска producer/worker.

- Command считается успешным после `accepted` или `staged`, но не после выполнения handler.

- Worker использует отдельное runtime-соединение без чужой транзакции; child создаёт process-local resources после fork.

- Для package-owned wiring используйте `PostgresRuntime::create()`. Он
  возвращает bus, worker, backend, schema manager, delivery
  operations/maintenance, idempotency maintenance и worker control, собранные
  вокруг одного PDO и registry. Стандартные codecs выводятся автоматически:

```php
$runtime = PostgresRuntime::create(
    pdo: $processLocalPdo,
    registry: $registry,
    handlers: $handlerResolver,
    routes: $routes,
    policies: $policies,
    schema: 'application',
);

$receipt = $runtime->bus->dispatch($command);
$iteration = $runtime->worker->runOnce($profile, $workerId);
```

  Host по-прежнему создаёт и закрывает PDO, применяет migrations отдельным
  deploy-шагом, выбирает routes/policies и строит новый runtime после каждого
  fork. В child нельзя передавать унаследованный parent PDO.

- Запустите `composer example:durable` на disposable PostgreSQL; in-memory backend не доказывает persistence.

Точные signatures перечислены в [публичном API](../reference/public-api.md); при конфликте source manifest является нормативным.
