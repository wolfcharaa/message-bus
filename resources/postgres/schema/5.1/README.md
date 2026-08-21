# MessageBus PostgreSQL schema 5.1

Эти SQL-файлы являются source-of-truth templates для PostgreSQL schema MessageBus 5.1.

Файлы:

- `queue.sql` - queue jobs schema, включая `interrupted` status и `message_bus__schema_versions` component `queue`.
- `worker-control.sql` - worker control-plane schema, включая commands, deliveries, acknowledgements, audit, desired state и `message_bus__schema_versions` component `worker_control`.
- `all.sql` - queue + worker-control schema в одном файле.

Порядок применения в приложении:

1. Остановить workers.
2. Применить SQL через migration tool приложения.
3. Запустить `vendor/bin/message-bus message-bus:postgres:schema:validate`.
4. Запустить workers.

Тест `PostgresMigrationSqlTest` проверяет, что SQL templates совпадают с текущими schema generators.

PostgreSQL integration tests:

```bash
docker compose -f docker-compose.integration.yml up -d --wait
vendor/bin/phpunit -c phpunit.integration.xml.dist
```

Process integration tests:

```bash
vendor/bin/phpunit -c phpunit.process.xml.dist
```
