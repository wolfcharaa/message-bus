# MessageBus

Attribute-driven PHP message bus для command/query/event сценариев, async очередей, worker-ов, cache result и понятного runtime control.

Библиотека помогает вынести правила выполнения сообщений из application code в явный registry: какие сообщения есть, какие handlers их обрабатывают, какие flows используются, что выполняется sync, что уходит в queue, как это сериализуется, кешируется, ретраится и контролируется в production.

## Зачем это ставить

MessageBus полезен, когда в приложении появляются такие проблемы:

- controller/service начинает напрямую знать слишком много handlers;
- sync command/query и async events смешаны в одном application code;
- события надо отправлять в очередь и потом показывать frontend статус выполнения;
- нужны стабильные message aliases и handler binding ids, чтобы refactoring PHP classes не ломал очередь;
- нужны retry, delay, priority, cancellation и polling задач;
- long-running workers надо ставить на pause, drain, restart или emergency kill;
- нужен один подход для Symfony, Laravel, Spiral, Yii, Mezzio, Slim или standalone PHP.

## Что предоставляет библиотека

| Возможность | Для чего нужна | Подробности |
| --- | --- | --- |
| `dispatch()` | Выполнить command/query синхронно и получить result | [Quick start](docs/guides/quick-start.md) |
| `publish()` | Опубликовать event в один или несколько handlers | [Event guide](docs/guides/events.md) |
| Flows | Разделить sync, async, queue, middleware и execution strategy | [Core concepts](docs/reference/core-concepts.md) |
| Compiled registry | Получить стабильную карту messages/handlers/aliases/bindings | [Core concepts](docs/reference/core-concepts.md) |
| Payload serialization | Выбрать JSON, PHP serialize, protobuf или custom payload | [Payload serialization](docs/guides/payload-serialization.md) |
| PostgreSQL queue | Поставить async jobs в БД и запускать workers | [Async queue](docs/guides/async-queue.md) |
| Queue status/control | Вернуть frontend `queueMessageId`, polling status и cancel | [Queue and worker](docs/reference/queue-and-worker.md) |
| Worker control plane | Управлять long-running workers через pause/resume/drain/stop/kill/restart | [Worker control plane](docs/reference/worker-control-plane.md) |
| Cache result | Кешировать результат query/command handler-а | [Cache result](docs/guides/cache-result.md) |
| PSR-11 integration | Подключить handlers и infrastructure через container | [Container contract](docs/reference/container-contract.md) |
| Framework integration | Подключить библиотеку в популярные frameworks | [Framework integration](docs/guides/framework-integration.md) |

## Общая модель

MessageBus строится вокруг простой цепочки:

```text
message -> envelope -> registry -> flow -> handler -> result / queue job
```

Что делает каждая часть:

| Часть | Простыми словами | Зачем нужна |
| --- | --- | --- |
| Message | DTO с намерением или фактом | Отделить business request/event от framework/controller кода |
| Handler | Service, который выполняет работу | Держать business logic в явной точке обработки |
| Registry | Скомпилированная карта messages, handlers, aliases и bindings | Не искать handlers в runtime магией и не держать wiring в голове |
| Envelope | Message плюс metadata | Передавать correlationId, causationId, headers, flow и bindingId |
| Flow | Правило “как выполнять” | Разделить sync, async, middleware, queue и strategy |
| Queue job | SerializedEnvelope в transport | Выполнить handler позже, в worker-е, с retry/status/cancel |
| Worker | Runtime для queue jobs | Надёжно брать задачи, выполнять handlers и обновлять lifecycle |
| Control plane | Команды управления workers | Pause, resume, drain, stop, kill, restart и status для production |

Главная идея: application code публикует messages, а библиотека по registry и flow решает, какой handler выполнить сейчас, какой поставить в queue, как сохранить metadata, как вернуть результат и как дать backend/frontend наблюдать состояние.

## Какие проблемы закрывает

### 1. Controller не должен знать все handlers

Без message bus controller часто напрямую вызывает services, events, queues и side effects.

С MessageBus controller отправляет один message:

```php
$result = $bus->dispatch(new CreateUserMessage($email, $name));
```

Дальше registry определяет, какой handler является primary, какой flow используется и какой result вернуть.

### 2. Events должны быть fan-out, а не цепочкой ручных вызовов

Один event может иметь несколько subscribers:

```php
$bus->publish(new UserCreatedEvent($userId));
```

Каждый subscriber получает свой `bindingId`, поэтому email, audit, webhook и analytics jobs становятся независимыми. Если один subscriber упал, остальные не обязаны падать вместе с ним.

### 3. Async job должен быть наблюдаемым

`publish()` возвращает `PublishResult`. Из него можно получить `queueMessageId` и вернуть его frontend.

Frontend может polling-ом спрашивать backend:

```php
$status = $runtime->queueStatus()?->get($queueMessageId);
```

Это закрывает обычный UX: “мы приняли задачу, она выполняется, вот её статус”.

### 4. Долгие jobs должны уметь отменяться

Для running job можно запросить cancellation:

```php
$runtime->queueControl()?->requestCancellation($queueMessageId);
```

Handler проверяет отмену кооперативно:

```php
$context->throwIfCancellationRequested();
```

Так задача завершается контролируемо, а runner переводит её в `cancelled`.

### 5. Workers должны управляться в production

Long-running workers нельзя просто “запустить и забыть”. Им нужны диагностика и управляющие команды.

```bash
vendor/bin/message-bus worker:status --bootstrap=config/message_bus_runtime.php --children
vendor/bin/message-bus worker:pause --bootstrap=config/message_bus_runtime.php --group=emails
vendor/bin/message-bus worker:drain --bootstrap=config/message_bus_runtime.php --group=emails --reason="deploy"
vendor/bin/message-bus worker:restart --bootstrap=config/message_bus_runtime.php --worker-name=emails-worker
```

Это позволяет безопасно делать deploy, maintenance, emergency stop и restart через supervisor/docker/systemd.

### 6. Queue payload не должен зависеть от PHP class name

Для async сообщений используется `MessageAlias`, а для handler job - `bindingId`.

```php
#[MessageAlias('user.created')]
final class UserCreatedEvent {}

#[EventSubscriber(
    message: UserCreatedEvent::class,
    flow: 'async',
    bindingId: 'user.created.send_welcome_email',
)]
final class SendWelcomeEmail {}
```

Если PHP class переименуют, старые queue jobs всё ещё можно восстановить по alias и binding id.

### 7. Библиотека не заменяет container и framework

MessageBus не пытается быть DI container, framework queue или application kernel.

Она ожидает PSR-11 container и использует его для:

- handlers;
- middleware;
- context factories;
- execution strategies;
- queue/runtime infrastructure.

Это делает интеграцию одинаковой для Symfony, Laravel, Spiral, Yii, Mezzio, Slim и standalone PHP.

## Что остаётся на стороне приложения

Библиотека предоставляет runtime и contracts, но не забирает у приложения business decisions.

Приложение отвечает за:

- какие messages существуют;
- какие handlers выполняют business logic;
- какие dependencies нужны handlers;
- какой container использовать;
- какие flows и queues нужны для нагрузки;
- как frontend показывает status/progress;
- как supervisor/docker/systemd перезапускает workers;
- какую serialization strategy выбрать для конкретного проекта.

MessageBus отвечает за:

- dispatch/publish API;
- envelope metadata;
- handler registry;
- sync/async execution flows;
- queue job lifecycle;
- retry/cancel/status contracts;
- worker runtime;
- worker control plane;
- serializer contracts.

## Как читать документацию

Если вы впервые открыли библиотеку, читайте в таком порядке:

1. README до конца, чтобы понять общую модель.
2. [Quick start](docs/guides/quick-start.md), чтобы собрать первый sync command.
3. [Event guide](docs/guides/events.md), если нужны events и fan-out.
4. [Async queue](docs/guides/async-queue.md), если нужны queue jobs и workers.
5. [Worker control plane](docs/reference/worker-control-plane.md), если workers будут жить в production.
6. [Migration v4 to v5](docs/migration/v4-to-v5.md), если обновляетесь с предыдущей версии.

## Install

```bash
composer require romanfedorskij/message-bus
```

## Requirements

Обязательно:

- PHP `^8.3`;
- `psr/container`;
- `psr/clock`;
- `psr/simple-cache`;
- `psr/log`;
- `symfony/console`;
- `symfony/var-exporter`;
- `ext-json`.

Опционально:

- `ext-pdo_pgsql` - для PostgreSQL queue transport;
- `ext-pcntl` - для `worker:run --mode=auto`;
- `ext-posix` - для process liveness checks и signals в `worker:run --mode=auto`;
- `ext-pgsql` - для PostgreSQL diagnostics/native support.

Container не входит в библиотеку намеренно. Используйте любой PSR-11 compatible container, например:

- [PHP-DI](https://php-di.org/);
- [Symfony DependencyInjection](https://symfony.com/doc/current/components/dependency_injection.html);
- [thesis-php/dic](https://github.com/thesis-php/dic);

## Quick start

Минимальный sync command состоит из message, handler, container, registry и `MessageBus`.

### 1. Message

```php
final class CreateUserMessage
{
    public function __construct(
        public readonly string $email,
        public readonly string $name,
    ) {
    }
}
```

Message - это DTO. Он описывает намерение или факт и не содержит business logic, database connection или framework request.

### 2. Handler

```php
use Wolfcharaa\MessageBus\Attribute\CommandHandler;
use Wolfcharaa\MessageBus\Context\MessageContextInterface;

#[CommandHandler(message: CreateUserMessage::class)]
final class CreateUserAction
{
    public function __invoke(CreateUserMessage $message, MessageContextInterface $context): string
    {
        return 'created:' . $message->email;
    }
}
```

Handler должен быть service в PSR-11 container. Dependencies передавайте через constructor, а не через message.

### 3. Container

```php
use Wolfcharaa\MessageBus\Context\DefaultMessageContextFactory;
use Wolfcharaa\MessageBus\Execution\SequentialExecutionStrategy;

$container->set(CreateUserAction::class, fn () => new CreateUserAction());
$container->set(DefaultMessageContextFactory::class, fn () => new DefaultMessageContextFactory());
$container->set(SequentialExecutionStrategy::class, fn () => new SequentialExecutionStrategy());
```

### 4. Registry

```php
use Wolfcharaa\MessageBus\Discovery\ClassListProvider;
use Wolfcharaa\MessageBus\Flow\FlowRegistry;
use Wolfcharaa\MessageBus\Registry\CompiledMessageRegistry;
use Wolfcharaa\MessageBus\Registry\MessageRegistryCompiler;

$definition = (new MessageRegistryCompiler())->compile(
    new ClassListProvider([
        CreateUserMessage::class,
        CreateUserAction::class,
    ]),
    new FlowRegistry(),
    '5.0.0',
);

$registry = new CompiledMessageRegistry($definition);
```

Registry отвечает на вопросы: какие messages есть, какие handlers к ним привязаны, какие flows используются, какой binding является primary.

### 5. MessageBus

```php
use Wolfcharaa\MessageBus\MessageBus;

$bus = new MessageBus(
    registry: $registry,
    flows: $registry->definition()->flows,
    container: $container,
);

$result = $bus->dispatch(new CreateUserMessage('user@example.com', 'Roman'));
```

`dispatch()` возвращает business result primary sync handler-а.

Подробный разбор quick start: [docs/guides/quick-start.md](docs/guides/quick-start.md).

## Event quick start

Для events используйте `MessageAlias` и стабильный `bindingId`.

```php
use Wolfcharaa\MessageBus\Attribute\EventSubscriber;
use Wolfcharaa\MessageBus\Attribute\MessageAlias;

#[MessageAlias('user.created')]
final class UserCreatedEvent
{
    public function __construct(public readonly string $userId) {}
}

#[EventSubscriber(
    message: UserCreatedEvent::class,
    flow: 'async',
    bindingId: 'user.created.send_welcome_email',
)]
final class SendWelcomeEmail
{
    public function __invoke(UserCreatedEvent $event): void
    {
    }
}
```

`MessageAlias` нужен для стабильного serialized name сообщения. `bindingId` нужен для стабильной identity конкретной handler job в queue.

Подробности: [docs/guides/events.md](docs/guides/events.md).

## Async queue и workers

Встроенный PostgreSQL runtime закрывает producer, queue storage, consumer, worker и status/control repositories.

```php
use Wolfcharaa\MessageBus\Runtime\MessageBusRuntime;
use Wolfcharaa\MessageBus\Postgres\CallbackPdoConnectionProvider;
use Wolfcharaa\MessageBus\Postgres\PostgresRetryConfig;
use PDO;

$runtime = MessageBusRuntime::postgres(
    pdo: new CallbackPdoConnectionProvider(static fn (): PDO => new PDO($dsn, $user, $password)),
    registry: $registry,
    container: $container,
    flows: $flows,
    postgresRetryConfig: PostgresRetryConfig::default(),
);
```

Для production workers лучше передавать reconnect-capable provider, например `CallbackPdoConnectionProvider`.
Если передать готовый `PDO`, runtime обернет его в `StaticPdoConnectionProvider`: обычные запросы будут работать, но reconnect невозможен, и при transient disconnect библиотека упадет с явной ошибкой.

Создать schema:

```bash
vendor/bin/message-bus schema:postgres --with=all
```

Для production migration можно использовать SQL templates из `resources/postgres/schema/5.1`.

Проверить schema:

```bash
vendor/bin/message-bus message-bus:postgres:schema:validate \
  --dsn='pgsql:host=127.0.0.1;port=5432;dbname=app' \
  --user='app' \
  --password='secret'
```

Запустить single worker:

```bash
vendor/bin/message-bus worker:run --bootstrap=config/message_bus_runtime.php
```

Запустить auto worker с child processes:

```bash
vendor/bin/message-bus worker:run \
  --bootstrap=config/message_bus_runtime.php \
  --mode=auto \
  --workers=4 \
  --worker-name=emails-worker \
  --worker-group=emails \
  --output-verbosity=normal \
  --output-format=text \
  --storage-failure-backoff=1000 \
  --max-heartbeat-failures=3
```

`--output-verbosity` управляет stdout/stderr событиями worker-а: `quiet`, `normal`, `debug`, `trace`.
`--output-format` может быть `text` для Docker logs или `json` для log collectors.
`--storage-failure-backoff` и `--max-heartbeat-failures` задают базовую hybrid failure policy после exhausted retry.

Подробности: [docs/guides/async-queue.md](docs/guides/async-queue.md) и [docs/reference/queue-and-worker.md](docs/reference/queue-and-worker.md).

## Worker control plane

Worker control plane нужен для эксплуатации long-running workers.

Самые частые команды:

```bash
vendor/bin/message-bus worker:status --bootstrap=config/message_bus_runtime.php --children
vendor/bin/message-bus worker:pause --bootstrap=config/message_bus_runtime.php --group=emails --reason="maintenance"
vendor/bin/message-bus worker:resume --bootstrap=config/message_bus_runtime.php --group=emails
vendor/bin/message-bus worker:drain --bootstrap=config/message_bus_runtime.php --group=emails --reason="deploy"
vendor/bin/message-bus worker:restart --bootstrap=config/message_bus_runtime.php --worker-name=emails-worker --reason="config reload"
vendor/bin/message-bus worker:kill --bootstrap=config/message_bus_runtime.php --worker-instance-id=emails-app-01-1 --reason="stuck child"
```

Коротко:

- `status` - посмотреть живые workers и children;
- `pause` - временно не брать новые jobs;
- `resume` - вернуть paused workers в работу;
- `drain` - перестать брать jobs, дождаться running children и выйти;
- `stop` - штатно остановить worker;
- `kill` - аварийно завершить children через signals;
- `restart` - graceful drain и exit code для supervisor/docker/systemd.

Подробности и сценарии: [docs/reference/worker-control-plane.md](docs/reference/worker-control-plane.md).

## Payload serialization

По умолчанию используется JSON payload. Для PHP-only проектов можно использовать PHP serialize. Для protobuf/binary форматов используйте custom serializer с явным `contentType`.

Подробности: [docs/guides/payload-serialization.md](docs/guides/payload-serialization.md).

## Миграция с v4 на v5

v5 не сохраняет совместимость registry/schema с v4.

Минимальный safe path:

- остановить или drain-нуть v4 producers/workers;
- дать v4 workers завершить старые jobs;
- применить v5 schema;
- пересобрать compiled registry cache;
- задеплоить v5 producers/workers вместе;
- запустить v5 workers.

Подробная инструкция: [docs/migration/v4-to-v5.md](docs/migration/v4-to-v5.md).

## Миграция с v5.0 на v5.1

v5.1 расширяет PostgreSQL schema для worker control-plane и добавляет schema validation.

Минимальный safe path:

- остановить или drain-нуть v5.0 workers;
- применить SQL из `resources/postgres/schema/5.1/all.sql`;
- запустить `message-bus:postgres:schema:validate`;
- перезапустить workers;
- проверить `worker:status`.

Подробная инструкция: [docs/migration/v5.0-to-v5.1.md](docs/migration/v5.0-to-v5.1.md).

## Framework integration

Библиотека не навязывает framework. Основной контракт - PSR-11 container.

Подключение для Generic PSR-11, Symfony, Laravel, Spiral и Yii3 вынесено в [docs/guides/framework-integration.md](docs/guides/framework-integration.md).

## Документация по разделам

| Раздел | Документ |
| --- | --- |
| Подробный быстрый старт | [docs/guides/quick-start.md](docs/guides/quick-start.md) |
| События, `MessageAlias` и `bindingId` | [docs/guides/events.md](docs/guides/events.md) |
| Async очередь и запуск worker-а | [docs/guides/async-queue.md](docs/guides/async-queue.md) |
| Сериализация payload | [docs/guides/payload-serialization.md](docs/guides/payload-serialization.md) |
| Миграция с v4 на v5 | [docs/migration/v4-to-v5.md](docs/migration/v4-to-v5.md) |
| Миграция с v5.0 на v5.1 | [docs/migration/v5.0-to-v5.1.md](docs/migration/v5.0-to-v5.1.md) |
| Основные концепции | [docs/reference/core-concepts.md](docs/reference/core-concepts.md) |
| Контракт контейнера | [docs/reference/container-contract.md](docs/reference/container-contract.md) |
| Контракты очереди и worker-а | [docs/reference/queue-and-worker.md](docs/reference/queue-and-worker.md) |
| Управление worker-ами | [docs/reference/worker-control-plane.md](docs/reference/worker-control-plane.md) |
| Кеширование результата | [docs/guides/cache-result.md](docs/guides/cache-result.md) |
| Логирование через middleware | [docs/guides/logging.md](docs/guides/logging.md) |
| Подключение к frameworks | [docs/guides/framework-integration.md](docs/guides/framework-integration.md) |
| Готовые примеры | [docs/examples](docs/examples) |

## Tests

```bash
composer test
```

Default suite не запускает внешние integration tests.

PostgreSQL integration profile:

```bash
docker compose -f docker-compose.integration.yml up -d --wait
vendor/bin/phpunit -c phpunit.integration.xml.dist
```

Process/pcntl integration profile:

```bash
vendor/bin/phpunit -c phpunit.process.xml.dist
```

Если PostgreSQL уже поднят отдельно, можно передать DSN явно:

```bash
MESSAGE_BUS_TEST_PGSQL_DSN='pgsql:host=127.0.0.1;port=5432;dbname=messagebus' \
MESSAGE_BUS_TEST_PGSQL_USER='messagebus' \
MESSAGE_BUS_TEST_PGSQL_PASSWORD='messagebus' \
vendor/bin/phpunit -c phpunit.integration.xml.dist
```
