# MessageBus

[English](README.en.md)

Типизированная шина сообщений для PHP 8.3+ с детерминированным реестром и
опциональной надёжной доставкой через PostgreSQL и PCNTL. Библиотека даёт один
`MessageBus::dispatch()` для Query, Request, Command и Event; DI-контейнер,
бизнес-транзакции, развёртывание и авторизация остаются у приложения.

## Установка

```bash
composer require romanfedorskij/message-bus:^7.0@beta
```

Для durable profile нужны `ext-pdo_pgsql`, `ext-pcntl` и `ext-posix`. Symfony
Console, PSR-11 и PSR-3 подключаются только для соответствующих adapters; core
требует лишь PHP, JSON и `psr/clock`.

## Выберите путь

- [Оркестрация без очереди](docs/ru/getting-started/orchestration.md) —
  Query/Request и inline Event в вызывающем процессе.
- [Durable PostgreSQL](docs/ru/getting-started/durable-postgres.md) — Command,
  durable Event, готовая `PostgresRuntime` composition, delivery status/control
  и worker processes.
- [Автопоиск handlers через Composer](docs/ru/getting-started/composer-directory-discovery.md) —
  быстрый старт без ручного списка классов, безопасная dev-пересборка и
  production artifact без filesystem scan.
- [Handler middleware](docs/ru/guides/handler-middleware.md) — global chain,
  локальные interceptors в `#[Handler]` и reusable middleware durable policy.

После установки проверьте минимальную композицию из поставляемого примера:

```bash
php vendor/romanfedorskij/message-bus/examples/orchestration/run.php
# Hello Ada
```

Основной вызов в приложении выглядит так; полная композиция приведена ниже по
ссылке:

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

Для автоматического поиска production PSR-4/classmap declarations установите
опциональный build/dev parser:

```bash
composer require --dev composer/class-map-generator:^1.8
```

Он не нужен в production после сборки artifact.

В framework с PSR-11 передайте контейнер через
`PsrContainerHandlerServiceResolver`: исходные autowiring exceptions не
заменяются ошибкой библиотеки. Для стандартных durable messages codecs можно
получить через `MessageCodecs::fromRegistry($registry)`, а команды MessageBus —
зарегистрировать в существующем Symfony `Application` методами `Console::register*()`.

## Контракт

- Query/Request выполняются сразу и имеют ровно один handler.
- Command всегда durable и возвращает `CommandReceipt` после `staged` либо
  `accepted`, а не после исполнения. Не зависящий от PHP class target задаётся
  через `#[Handler(bindingId: 'command.name')]`.
- Event пытается доставить каждый subscription и при partial failure бросает
  `PublicationFailed` с полным `PublicationReceipt`.
- Handler attempt выполняется в явном порядке global middleware → middleware
  delivery policy → middleware конкретного `#[Handler]` → handler. Все class
  references разрешаются через тот же DI scope, а container failures не
  подменяются.
- PostgreSQL adapter использует DB time, `SKIP LOCKED` и fenced lease CAS.
- Production worker — PCNTL parent с fresh child/runtime на одну attempt.
- Публичны только symbols с `@api`, перечисленные в
  [generated reference](docs/ru/reference/public-api.md). `Internal` не является
  extension contract.
- Для разбора сбоя используйте
  [пошаговое руководство](docs/ru/guides/troubleshooting-diagnostics.md), затем
  найдите точный `MBR`, `MBC`, `MBD` или `MBCLI` в
  [справочнике диагностик](docs/ru/reference/diagnostics.md).

Карта возможностей и дальнейших задач находится в
[RU documentation](docs/ru/reference/capability-map.md), английская версия — в
[README.en.md](README.en.md). Переход со стабильной v6 описан в
[migration guide](docs/ru/migration/v6-to-v7.md); compatibility shims для
Envelope/Flow/queue internals намеренно отсутствуют.

## Agent integration (opt-in)

Package содержит `$integrate-message-bus`, который сначала исследует host
application и строит минимальную configuration map. Он не активируется сам:

```bash
composer require --dev llm/skills
composer skills:init --quick
composer skills:update romanfedorskij/message-bus
composer skills:show romanfedorskij/message-bus
```

Последняя команда должна показать `integrate-message-bus` со статусом `OK`.
Плагин копирует skill в настроенный каталог агентов; vendor-копию не редактируют.

## Разработка

```bash
composer test
composer test:process
composer analyse
composer cs:check
composer rector:check
composer api:check
composer docs:check
composer skills:validate
```

PostgreSQL integration и durable example требуют disposable database. Все
release, migration и consumer-rehearsal действия выполняются отдельно и никогда
не запускаются package bootstrap-ом.

Лицензия: [MIT](LICENSE).
