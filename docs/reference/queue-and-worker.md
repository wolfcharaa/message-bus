# Queue and worker

Этот раздел описывает queue contracts. Он нужен, если вы используете встроенный PostgreSQL adapter глубже, пишете свой transport adapter или интегрируете библиотеку с framework worker.

Queue слой разделён на несколько ролей:

- `QueueProviderInterface` - producer-side запись задач.
- `MessageConsumerInterface` - worker-side чтение и lifecycle задач.
- `QueueWorkerInterface` - выполнение одного serialized envelope.
- `QueueWorkerRunner` - loop, который связывает consumer и worker.
- `QueueStatusRepositoryInterface` - чтение статуса задач для API/polling.
- `QueueJobControlInterface` - cancel/request cancellation/heartbeat/cancellation state.

## Producer side: QueueProviderInterface

Producer side используется внутри async execution strategy, когда `publish()` должен поставить handler в очередь.

```php
interface QueueProviderInterface
{
    public function enqueue(QueueMessage $message): QueueEnqueueResult;
}
```

`QueueMessage` содержит уже подготовленную задачу:

- `transport` - backend transport, например `postgres`.
- `queue` - имя очереди внутри transport.
- `envelope` - serialized message + metadata.
- `messageId` - id исходного envelope.
- `correlationId` - id всей цепочки.
- `flow` - async flow.
- `bindingId` - конкретный handler binding.
- `availableAt` - когда задачу можно брать в работу.
- `priority` - приоритет.
- `retryPolicyKey` - имя retry policy.
- `retryPolicySnapshot` - зафиксированные retry настройки на момент enqueue.

Почему retry snapshot хранится в задаче: если policy изменится после enqueue, старая задача должна продолжить жить по правилам, которые были выбраны при публикации.

`QueueEnqueueResult` возвращает:

- `queueMessageId` - id задачи, который можно вернуть frontend.
- `backendId` - id backend-а, если transport использует отдельный id.
- `status` - обычно `pending`.
- `createdAt` - время создания.
- `metadata` - дополнительные данные adapter-а.

## Batch producer: BatchQueueProviderInterface

Если transport умеет пачечную запись, он может реализовать batch interface:

```php
interface BatchQueueProviderInterface extends QueueProviderInterface
{
    public function enqueueMany(iterable $messages): QueueBatchEnqueueResult;
}
```

MessageBus использует batch provider для `publishMany()` и fan-out событий, где один publish может создать несколько queue jobs.

Практическое правило: если backend поддерживает transaction/batch insert, adapter должен реализовать `BatchQueueProviderInterface`, чтобы не получать частично записанные fan-out задачи без явной ошибки.

## Consumer side: MessageConsumerInterface

Consumer side используется worker-ом. Он отвечает за то, чтобы безопасно взять задачу, изменить её status и не дать двум worker-ам выполнить одну строку одновременно.

```php
interface MessageConsumerInterface
{
    public function next(ConsumerOptions $options): ?ReceivedQueueMessage;
    public function ack(ReceivedQueueMessage $message): void;
    public function retry(ReceivedQueueMessage $message, Throwable $reason): void;
    public function reject(ReceivedQueueMessage $message, Throwable $reason): void;
    public function cancel(ReceivedQueueMessage $message, Throwable $reason): void;
}
```

Методы lifecycle:

- `next()` - найти и заблокировать следующую задачу.
- `ack()` - подтвердить успешное выполнение и поставить `succeeded`.
- `retry()` - вернуть задачу в `pending` с новым `availableAt`.
- `reject()` - завершить задачу как `failed`.
- `cancel()` - завершить задачу как `cancelled`.

`ReceivedQueueMessage` содержит:

- `queueMessageId` - id задачи в queue storage.
- `message` - исходный `QueueMessage`.
- `attempts` - сколько попыток уже было сделано.
- `raw` - adapter-specific row/message, если нужен низкоуровневый доступ.

## ConsumerOptions

Worker передаёт consumer-у фильтры и limits:

```php
new ConsumerOptions(
    transport: 'postgres',
    queue: 'default',
    timeoutSeconds: 5,
    limit: 1,
    workerId: 'message-bus-worker-1',
    lockTtlSeconds: 300,
    flows: [],
    bindingIds: [],
    bindingPatterns: [],
);
```

Поля:

- `transport` и `queue` выбирают очередь.
- `timeoutSeconds` задаёт ожидание задачи, если adapter это поддерживает.
- `limit` задаёт максимум задач за один read cycle.
- `workerId` сохраняется в storage для диагностики.
- `lockTtlSeconds` нужен для восстановления зависших `running` задач.
- `flows` ограничивает worker конкретными flows.
- `bindingIds` ограничивает worker конкретными bindings.
- `bindingPatterns` позволяет запускать worker по маске binding id.

Примеры:

```php
// Worker только для email задач.
new ConsumerOptions(
    transport: 'postgres',
    queue: 'default',
    bindingPatterns: ['*.send_email', '*.send_welcome_email'],
);
```

```php
// Worker только для одного heavy binding.
new ConsumerOptions(
    transport: 'postgres',
    queue: 'reports',
    bindingIds: ['report.requested.build_pdf'],
);
```

## QueueWorkerInterface

Worker получает не PHP message object, а `SerializedEnvelope`.

```php
$worker->handle($serializedEnvelope);
```

Встроенный `MessageBusQueueWorker` делает:

- десериализует message через `EnvelopeSerializerInterface`;
- восстанавливает envelope metadata;
- находит binding по `bindingId`;
- запускает handler через MessageBus pipeline;
- возвращает result или выбрасывает exception.

Если lifecycle уже управляется внешним framework worker, можно использовать только `QueueWorkerInterface` и отдать ему serialized envelope из своего queue backend.

## QueueWorkerRunner

`QueueWorkerRunner` - это готовый loop:

```php
$result = $runner->run(
    new ConsumerOptions('postgres', 'default'),
    new QueueWorkerRunnerOptions(
        maxMessages: 100,
        maxRuntimeSeconds: 300,
        idleTimeoutSeconds: 30,
        stopWhenEmpty: false,
        memoryLimitBytes: 256 * 1024 * 1024,
    ),
);
```

Runner делает:

- вызывает `consumer->next()`;
- создаёт scoped runtime control для текущего queue job, если runner получил `QueueJobControlInterface`;
- передаёт envelope в `worker->handle()`;
- вызывает `ack()` при успехе;
- вызывает `retry()` при retryable exception;
- вызывает `reject()` при non-retryable exception или исчерпании попыток;
- вызывает `cancel()` при cancellation exception;
- останавливается по limits, signal provider, idle timeout или memory limit.

`QueueWorkerRunResult` возвращает counters:

- `handled`;
- `succeeded`;
- `retried`;
- `rejected`;
- `cancelled`.

## Retry behavior

Retry решение принимает runner:

- если handler завершился успешно, вызывается `ack()`;
- если exception реализует `NonRetryableMessageExceptionInterface`, вызывается `reject()`;
- если попытки закончились, вызывается `reject()`;
- иначе вызывается `retry()`;
- если exception реализует `MessageCancellationExceptionInterface`, вызывается `cancel()`.

Retry delay рассчитывает queue storage/adapter на основе `RetryPolicySnapshot`.

Default snapshot:

- max attempts: `3`;
- strategy: `exponential`;
- initial delay: `30` seconds;
- multiplier: `2.0`;
- max delay: `300` seconds.

## Status and polling

Для frontend/API polling используется `QueueStatusRepositoryInterface`:

```php
interface QueueStatusRepositoryInterface
{
    public function get(string $queueMessageId): ?QueueJobStatus;
    public function listByMessageId(string $messageId): array;
    public function listByCorrelationId(string $correlationId): array;
}
```

Использование:

```php
$status = $runtime->queueStatus()?->get($queueMessageId);
```

`listByCorrelationId()` полезен, когда один пользовательский request породил несколько events и несколько queue jobs. Так можно показать frontend общий progress всей цепочки.

## Job control

Для управления задачами используется `QueueJobControlInterface`:

```php
interface QueueJobControlInterface
{
    public function cancel(string $queueMessageId): void;
    public function requestCancellation(string $queueMessageId): void;
    public function heartbeat(string $queueMessageId): void;
    public function isCancellationRequested(string $queueMessageId): bool;
}
```

Разница:

- `cancel()` отменяет pending задачу.
- `requestCancellation()` просит running задачу остановиться.
- `heartbeat()` обновляет признак живого выполнения задачи.
- `isCancellationRequested()` возвращает флаг cooperative cancellation для handler context.

Running handler не прерывается магически. Если нужна cooperative cancellation, handler должен периодически проверять флаг отмены через `CancellableMessageContextInterface`.

```php
use Wolfcharaa\MessageBus\Context\CancellableMessageContextInterface;
use Wolfcharaa\MessageBus\Context\HeartbeatAwareMessageContextInterface;

public function __invoke(LongImport $message, CancellableMessageContextInterface $context): void
{
    foreach ($this->reader->chunks($message->file) as $chunk) {
        $context->throwIfCancellationRequested();

        $this->importer->import($chunk);

        if ($context instanceof HeartbeatAwareMessageContextInterface) {
            $context->heartbeat();
        }
    }
}
```

Встроенный `DefaultMessageContext` реализует оба интерфейса. Вне worker-а `isCancellationRequested()` вернёт `false`, а `heartbeat()` будет no-op. Внутри worker-а runner прокидывает scoped control текущей queue job.

## PostgreSQL adapter

Встроенный PostgreSQL adapter закрывает полный lifecycle:

- enqueue;
- batch enqueue;
- next with locking;
- ack;
- retry;
- reject;
- cancel;
- heartbeat;
- stale running recovery;
- status polling;
- cancellation request.

PostgreSQL storage использует `FOR UPDATE SKIP LOCKED`, чтобы несколько worker-ов могли безопасно читать одну таблицу без выполнения одной задачи дважды.

## Когда писать свой queue adapter

Свой adapter нужен, если вы хотите использовать:

- RabbitMQ;
- Redis streams;
- SQS;
- Kafka;
- framework queue;
- существующую таблицу задач приложения.

Минимум для producer-only adapter:

- реализовать `QueueProviderInterface`;
- сохранить `SerializedEnvelope`;
- вернуть стабильный `queueMessageId`.

Минимум для полноценного worker adapter:

- реализовать `MessageConsumerInterface`;
- обеспечить exclusive claim задачи в `next()`;
- корректно реализовать `ack/retry/reject/cancel`;
- хранить attempts/max attempts или совместимый retry state;
- вернуть `ReceivedQueueMessage` с исходным `QueueMessage`.

Если нужен polling frontend-а, добавьте `QueueStatusRepositoryInterface`. Если нужна отмена задач, добавьте `QueueJobControlInterface`.

Для полноценного cooperative cancellation adapter должен реализовать все методы `QueueJobControlInterface`: отмену pending задач, запрос отмены running задач, heartbeat running job и чтение cancellation flag.
