# MessageBus

Типизированный PHP 8.3+ message bus с детерминированным реестром и опциональной
durable delivery через PostgreSQL и PCNTL. Библиотека даёт один
`MessageBus::dispatch()` для Query, Request, Command и Event; DI, business
transactions, deployment и authorization остаются у приложения.

## Установка

```bash
composer require romanfedorskij/message-bus:^7.0@alpha
```

Для durable profile нужны `ext-pdo_pgsql`, `ext-pcntl` и `ext-posix`. Symfony
Console и PSR-3 подключаются только для соответствующих adapters; core требует
лишь PHP, JSON и `psr/clock`.

## Выберите путь

- [Оркестрация без очереди](docs/ru/getting-started/orchestration.md) —
  Query/Request и inline Event в вызывающем процессе.
- [Durable PostgreSQL](docs/ru/getting-started/durable-postgres.md) — Command,
  durable Event, delivery status/control и worker processes.

Минимальный наблюдаемый путь:

```php
/** @implements \Wolfcharaa\MessageBus\Contract\Query<string> */
final readonly class Greeting implements \Wolfcharaa\MessageBus\Contract\Query
{
    public function __construct(public string $name) {}
}

final class GreetingHandler
{
    #[\Wolfcharaa\MessageBus\Attribute\Handler]
    public function __invoke(Greeting $query): string
    {
        return 'Hello ' . $query->name;
    }
}

$answer = $bus->dispatch(new Greeting('Ada'));
```

Полная composition находится в
[`examples/orchestration/run.php`](examples/orchestration/run.php). Production
registry компилируется при сборке и загружается как self-hashed artifact; он не
сканирует handlers на каждом старте.

## Контракт

- Query/Request выполняются сразу и имеют ровно один handler.
- Command всегда durable и возвращает `CommandReceipt` после `staged` либо
  `accepted`, а не после исполнения.
- Event пытается доставить каждый subscription и при partial failure бросает
  `PublicationFailed` с полным `PublicationReceipt`.
- PostgreSQL adapter использует DB time, `SKIP LOCKED` и fenced lease CAS.
- Production worker — PCNTL parent с fresh child/runtime на одну attempt.
- Публичны только symbols с `@api`, перечисленные в
  [generated reference](docs/ru/reference/public-api.md). `Internal` не является
  extension contract.
- Все коды `MBR`, `MBC`, `MBD` и `MBCLI`, причины и способы исправления собраны
  в [справочнике диагностик](docs/ru/reference/diagnostics.md).

Операционные задачи собраны в [RU documentation](docs/ru), английская версия —
в [README.en.md](README.en.md). Переход со стабильной v6 описан в
[migration guide](docs/ru/migration/v6-to-v7.md); compatibility shims для
Envelope/Flow/queue internals намеренно отсутствуют.

## Agent integration (opt-in)

Package содержит `$integrate-message-bus`, который сначала исследует host
application и строит минимальную configuration map. Он не активируется сам:

```bash
composer require --dev llm/skills
composer skills:init --quick
composer skills:update romanfedorskij/message-bus
```

## Разработка

```bash
composer test
composer test:process
composer analyse
composer cs:check
composer rector:check
composer api:check
composer docs:check
```

PostgreSQL integration и durable example требуют disposable database. Все
release, migration и consumer-rehearsal действия выполняются отдельно и никогда
не запускаются package bootstrap-ом.

Лицензия: [MIT](LICENSE).
