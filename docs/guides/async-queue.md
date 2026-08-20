# Async queue quick path

Async queue нужна, когда handler не должен выполняться прямо в HTTP request или CLI command.

Типичные причины:

- работа долгая;
- работу можно повторить при ошибке;
- frontend должен получить id задачи и смотреть её состояние;
- несколько handlers одного event-а должны выполняться независимо;
- часть handlers должна выполняться сейчас, а часть позже worker-ом;
- нужно ограничить concurrency через worker processes.

В async режиме `publish()` не вызывает handler сразу. Он сериализует message в envelope, создаёт queue job и возвращает `PublishResult` с `queueMessageId`.

Дальше отдельный worker читает queue job, восстанавливает envelope, находит handler по `bindingId` и выполняет его.

Общий путь:

```text
producer process
  -> $bus->publish($event)
  -> MessageBus находит async bindings в registry
  -> EnvelopeSerializer сериализует message
  -> QueueProvider создаёт jobs в PostgreSQL
  -> frontend получает queueMessageId

worker process
  -> MessageConsumer читает pending job
  -> QueueWorker восстанавливает envelope
  -> MessageBus выполняет конкретный binding
  -> Queue storage ставит status succeeded, failed, pending retry или cancelled
```

## 1. Flows

Flow отвечает за способ выполнения handler-а.

В минимальной конфигурации обычно есть два flow:

- `default` - sync flow, handler выполняется сразу.
- `async` - async flow, handler кладётся в очередь.

```php
use Wolfcharaa\MessageBus\Flow\FlowDefinition;
use Wolfcharaa\MessageBus\Flow\FlowRegistry;

$flows = new FlowRegistry(
    FlowDefinition::sync('default'),
    FlowDefinition::async('async')->transport('postgres', 'default'),
);
```

Что здесь происходит:

- `FlowDefinition::sync('default')` создаёт обычный синхронный flow.
- `FlowDefinition::async('async')` создаёт async flow.
- `transport('postgres', 'default')` говорит, что async jobs надо писать в PostgreSQL transport и queue `default`.

`transport` и `queue` нужны, чтобы later можно было разделить разные типы задач:

```php
FlowDefinition::async('emails')->transport('postgres', 'emails');
FlowDefinition::async('reports')->transport('postgres', 'reports');
FlowDefinition::async('crm')->transport('postgres', 'integrations');
```

## 2. Пометьте handler как async subscriber

Async handler должен быть привязан к async flow:

```php
#[EventSubscriber(
    message: UserCreatedEvent::class,
    flow: 'async',
    bindingId: 'user.created.send_welcome_email',
)]
final class SendWelcomeEmailAction
{
    public function __invoke(UserCreatedEvent $event, MessageContextInterface $context): void
    {
        // send email
    }
}
```

В этом примере:

- `message` говорит, какой event слушает handler.
- `flow: 'async'` говорит, что handler надо поставить в очередь.
- `bindingId` говорит, какая именно подписка будет выполнена worker-ом.

Если у event-а два async subscribers, `publish()` создаст две queue jobs:

```php
#[EventSubscriber(
    message: UserCreatedEvent::class,
    flow: 'async',
    bindingId: 'user.created.send_welcome_email',
)]
final class SendWelcomeEmailAction {}

#[EventSubscriber(
    message: UserCreatedEvent::class,
    flow: 'async',
    bindingId: 'user.created.write_audit_log',
)]
final class WriteAuditLogAction {}
```

Это две независимые задачи. У каждой будет свой `queueMessageId`, свой retry lifecycle и свой status.

## 3. Создайте PostgreSQL runtime

`MessageBusRuntime::postgres()` собирает готовую инфраструктуру для producer и worker.

```php
use Wolfcharaa\MessageBus\Runtime\MessageBusRuntime;

$runtime = MessageBusRuntime::postgres(
    pdo: $pdo,
    registry: $registry,
    container: $container,
    flows: $flows,
);

$bus = $runtime->bus();
```

Что создаёт runtime:

- `MessageBus` для `dispatch()` и `publish()`.
- `PostgresQueueStorage` для записи и чтения queue jobs.
- `PostgresQueueProvider` для producer-side enqueue.
- `PostgresMessageConsumer` для worker-side чтения jobs.
- `MessageBusQueueWorker` для выполнения handler-а из serialized envelope.
- `QueueWorkerRunner` для worker loop.
- `QueueStatusRepositoryInterface` для polling статусов.
- `QueueJobControlInterface` для cancel/cancellation request.

Producer обычно использует только:

```php
$bus = $runtime->bus();
```

Backend endpoint для polling может использовать:

```php
$statusRepository = $runtime->queueStatus();
```

Admin endpoint или cancel endpoint может использовать:

```php
$queueControl = $runtime->queueControl();
```

## 4. Создайте таблицы очереди и worker control

Если нужен только queue storage:

```bash
vendor/bin/message-bus queue:schema:postgres --table=message_bus__queue_jobs
```

Если нужен полный PostgreSQL runtime с worker control/status:

```bash
vendor/bin/message-bus schema:postgres --with=all
```

Можно сразу записать SQL в файл миграции:

```bash
vendor/bin/message-bus schema:postgres \
  --with=queue,worker-control \
  --output=database/migrations/message_bus.sql
```

Таблица называется `message_bus__queue_jobs`, чтобы было явно видно, что она принадлежит message-bus. Двойное `__` оставляет удобный namespace для будущих таблиц библиотеки.

Что хранится в queue job:

- `id` - `queueMessageId`, внутренний id задачи.
- `status` - `pending`, `running`, `succeeded`, `failed`, `cancelled`.
- `message_id` - id исходного message envelope.
- `correlation_id` - id всей цепочки сообщений.
- `flow` - async flow, например `async`.
- `binding_id` - конкретная подписка handler-а.
- `transport` и `queue` - где задача должна исполняться.
- `attempts` и `max_attempts` - retry state.
- `available_at` - когда задача может быть взята worker-ом.
- `serialized_envelope` - message, headers и metadata для восстановления handler execution.
- `last_error` и `last_error_details` - последняя ошибка worker-а.

## 5. Опубликуйте event

Producer code:

```php
$result = $bus->publish(new UserCreatedEvent(10));

foreach ($result->executions() as $execution) {
    // $execution is PublishedExecution
    // $execution->mode          queued
    // $execution->queueMessageId id задачи в PostgreSQL
    // $execution->messageId      id message envelope
    // $execution->correlationId  id всей цепочки
    // $execution->bindingId      какая подписка поставлена в очередь
    // $execution->status         pending
}
```

Пример ответа HTTP endpoint-а для frontend:

```php
$result = $bus->publish(new UserCreatedEvent($userId));

return [
    'tasks' => \array_map(
        static fn ($execution): array => [
            'queueMessageId' => $execution->queueMessageId,
            'messageId' => $execution->messageId,
            'correlationId' => $execution->correlationId,
            'bindingId' => $execution->bindingId,
            'status' => $execution->status?->value,
        ],
        $result->executions(),
    ),
];
```

Если event имеет два async subscribers, frontend получит два элемента в `tasks`.

`publish()` возвращает `PublishResult`:

```php
$result->executions(); // successful sync executions or queued async executions
$result->failures();   // failures collected during publish
$result->hasFailures();
$result->isEmpty();
```

Если enqueue одной из задач упал, будет выброшен `PublishFailed` с partial result. Это нужно, чтобы backend мог понять, какие задачи уже попали в очередь, а какие нет.

## 6. Сделайте endpoint для polling статуса

Frontend обычно получает `queueMessageId` после `publish()` и периодически спрашивает backend:

```http
GET /message-bus/tasks/{queueMessageId}
```

Backend endpoint:

```php
use Wolfcharaa\MessageBus\Queue\QueueJobState;

$status = $runtime->queueStatus()?->get($queueMessageId);

if ($status === null) {
    return ['found' => false];
}

return [
    'found' => true,
    'queueMessageId' => $status->queueMessageId,
    'status' => $status->status->value,
    'messageId' => $status->messageId,
    'correlationId' => $status->correlationId,
    'flow' => $status->flow,
    'bindingId' => $status->bindingId,
    'transport' => $status->transport,
    'queue' => $status->queue,
    'attempts' => $status->attempts,
    'maxAttempts' => $status->maxAttempts,
    'availableAt' => $status->availableAt->format(DATE_ATOM),
    'startedAt' => $status->startedAt?->format(DATE_ATOM),
    'finishedAt' => $status->finishedAt?->format(DATE_ATOM),
    'lastError' => $status->lastError,
    'isFinished' => \in_array($status->status, [
        QueueJobState::Succeeded,
        QueueJobState::Failed,
        QueueJobState::Cancelled,
    ], true),
];
```

Статусы:

- `pending` - задача ждёт выполнения.
- `running` - worker взял задачу и выполняет handler.
- `succeeded` - handler успешно завершился.
- `failed` - retry попытки закончились или задача была rejected.
- `cancelled` - задача отменена.

## 7. Сделайте cancel endpoint, если frontend должен уметь отменять задачи

Для pending задачи можно отменить выполнение:

```php
$runtime->queueControl()?->cancel($queueMessageId);
```

Для running задачи можно запросить отмену:

```php
$runtime->queueControl()?->requestCancellation($queueMessageId);
```

Важно: `requestCancellation()` только ставит флаг. Длинный handler должен сам периодически проверять cancellation state через context.

```php
use Wolfcharaa\MessageBus\Context\CancellableMessageContextInterface;
use Wolfcharaa\MessageBus\Context\HeartbeatAwareMessageContextInterface;

public function __invoke(BuildReport $message, CancellableMessageContextInterface $context): void
{
    foreach ($this->builder->steps($message->reportId) as $step) {
        if ($context instanceof HeartbeatAwareMessageContextInterface) {
            $context->heartbeat();
        }

        $context->throwIfCancellationRequested();

        $this->builder->runStep($step);
    }
}
```

`throwIfCancellationRequested()` выбрасывает cancellation exception. Встроенный runner поймает его и переведёт queue job в `cancelled`.

## 8. Подготовьте bootstrap для worker

Worker запускается отдельным процессом. Ему нужен bootstrap file, который возвращает runtime.

Пример `config/message_bus_runtime.php`:

```php
<?php

declare(strict_types=1);

use Wolfcharaa\MessageBus\Runtime\MessageBusRuntime;

require dirname(__DIR__) . '/vendor/autoload.php';

$container = require __DIR__ . '/container.php';
$pdo = $container->get(PDO::class);
$registry = $container->get(CompiledMessageRegistry::class);
$flows = $container->get(FlowRegistry::class);

return MessageBusRuntime::postgres(
    pdo: $pdo,
    registry: $registry,
    container: $container,
    flows: $flows,
);
```

Bootstrap может вернуть:

- `MessageBusRuntime` - полный runtime, лучший вариант для встроенного worker-а.
- `QueueWorkerRunner` - если runner собран приложением вручную.
- PSR-11 container - если в нём зарегистрирован `MessageBusInterface` и нужные queue services.

## 9. Запустите worker

Single-process mode:

```bash
vendor/bin/message-bus worker:run --bootstrap=config/message_bus_runtime.php
```

Полезные параметры:

```bash
vendor/bin/message-bus worker:run \
  --bootstrap=config/message_bus_runtime.php \
  --transport=postgres \
  --queue=default \
  --worker-name=emails-worker \
  --worker-group=emails \
  --max-messages=100 \
  --stop-when-empty
```

Auto mode через `pcntl` master/child processes:

```bash
vendor/bin/message-bus worker:run \
  --bootstrap=config/message_bus_runtime.php \
  --mode=auto \
  --workers=4 \
  --worker-name=emails-worker \
  --worker-group=emails
```

Что делает worker:

- ищет `pending` job по `transport`, `queue` и `available_at`;
- блокирует строку через PostgreSQL locking;
- переводит job в `running`;
- восстанавливает `SerializedEnvelope`;
- по `bindingId` находит конкретный handler binding в registry;
- получает handler из PSR-11 container;
- выполняет handler через middleware pipeline;
- ставит `succeeded`, если handler завершился без exception;
- ставит `pending` с новым `available_at`, если нужна retry попытка;
- ставит `failed`, если попытки закончились;
- ставит heartbeat для контроля зависших worker-ов.

В auto mode главный процесс создаёт child processes. Каждый child заново загружает bootstrap, поэтому database connection и container resources создаются внутри child process.

Worker control команды работают через тот же bootstrap:

```bash
vendor/bin/message-bus worker:pause --bootstrap=config/message_bus_runtime.php --group=emails
vendor/bin/message-bus worker:resume --bootstrap=config/message_bus_runtime.php --group=emails
vendor/bin/message-bus worker:drain --bootstrap=config/message_bus_runtime.php --group=emails --reason="deploy"
vendor/bin/message-bus worker:restart --bootstrap=config/message_bus_runtime.php --worker-name=emails-worker
vendor/bin/message-bus worker:status --bootstrap=config/message_bus_runtime.php --children
```

## 10. Как MessageAlias и bindingId участвуют в async очереди

`MessageAlias` сохраняется в serialized envelope как `message.name`. Он нужен, чтобы worker мог восстановить PHP class даже после refactoring-а class name.

`bindingId` сохраняется в queue job отдельно. Он нужен, чтобы worker выполнил именно ту подписку, которая была поставлена в очередь.

Пример:

```text
message.name = user.created
binding_id = user.created.send_welcome_email
```

Это значит:

- восстановить message class по alias `user.created`;
- найти binding `user.created.send_welcome_email`;
- вызвать handler, который привязан к этому binding.

## 11. Как временно выполнить async синхронно

Для debug/local разработки можно принудительно выполнить async bindings в текущем процессе:

```bash
MESSAGE_BUS_FORCE_SYNC=1
```

В этом режиме задачи не попадут в queue provider, а `PublishResult` будет содержать executions с sync mode. Это удобно для отладки handler-а без worker-а и PostgreSQL очереди.
