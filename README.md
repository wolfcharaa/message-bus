# MessageBus

`romanfedorskij/message-bus` - PHP 8.3+ библиотека для запуска бизнес-действий через сообщения.

Она помогает отделить код, который принимает запрос пользователя, cron, webhook или queue job, от кода, который реально выполняет бизнес-операцию.

Вместо прямого вызова сервиса из controller:

```php
$result = $createUserAction->handle($email);
```

вы отправляете сообщение:

```php
$result = $bus->dispatch(new CreateUserMessage('user@example.com'));
```

А библиотека сама находит нужный handler, создаёт execution context, сохраняет `messageId/correlationId`, применяет middleware и, если нужно, кладёт задачу в очередь.

## Зачем это ставить

MessageBus полезен, если в проекте есть хотя бы одна из этих задач:

- Нужно одинаково запускать бизнес-действия из controller, CLI, cron, worker и тестов.
- Нужно явно разделить command, query и event.
- Нужно отправлять часть работы в очередь, но оставить тот же handler-код.
- Нужно возвращать frontend `queueMessageId`, чтобы он мог polling-ом смотреть состояние async задач.
- Нужно централизованно добавить retry, cache, logging, custom context или middleware.
- Нужно заранее собрать registry handlers для production без runtime reflection.

MessageBus не заменяет DI container, framework routing, ORM или queue server. Он связывает ваши сообщения, handlers, middleware и queue runtime в один предсказуемый workflow.

## Базовая идея

В библиотеке есть три основных понятия:

- `Message` - обычный immutable DTO, который описывает намерение.
- `Handler` - класс, который выполняет это намерение.
- `MessageBus` - объект, который принимает message и вызывает подходящий handler.

Типы сообщений:

- `Command` - сделать действие и вернуть результат, например `CreateUserMessage`.
- `Query` - прочитать данные и вернуть результат, например `FindUserMessage`.
- `Event` - сообщить, что что-то произошло, например `UserCreatedEvent`.

## Install

Для standalone PHP установите библиотеку и любой PSR-11 container. Например PHP-DI:

```bash
composer require romanfedorskij/message-bus php-di/php-di
```

В framework-проекте отдельный container обычно не нужен. Используйте container framework, если он доступен как `Psr\Container\ContainerInterface`.

## Requirements

Обязательные:

- PHP `^8.3`
- `psr/container`
- `psr/clock`
- `psr/log`
- `psr/simple-cache`
- `symfony/console`
- `symfony/var-exporter`
- `ext-json`
- PSR-11 compatible container implementation in application runtime

Опциональные:

- `ext-pcntl` для `worker:run --mode=auto`
- `ext-pdo_pgsql` для PostgreSQL queue transport
- `ext-pgsql` для PostgreSQL diagnostics/native support

Подходящие container implementations:

- [PHP-DI](https://php-di.org/) для standalone PHP.
- [Symfony DependencyInjection](https://symfony.com/doc/current/components/dependency_injection.html) container.
- [Laravel container](https://laravel.com/docs/container), если используется как PSR-11 container.
- [Spiral container](https://spiral.dev/docs/container-overview/current/en).
- [Yii DI container](https://www.yiiframework.com/doc/guide/3.0/en/concept-di-container).
- [DIC](https://github.com/thesis-php/dic).
- другой container через PSR-11 adapter.

## Quick Start

Этот пример показывает самый короткий путь: создать command, handler, registry и выполнить command синхронно.

### 1. Создайте message

Message - это DTO с данными, которые нужны handler-у.

```php
final class CreateUserMessage
{
    public function __construct(public readonly string $email) {}
}

final class CreateUserResult
{
    public function __construct(public readonly int $userId) {}
}
```

Result - это обычный объект, который вернётся из `dispatch()`.

### 2. Создайте handler

Handler помечается attribute-ом. Так registry понимает, какое сообщение обрабатывает этот класс.

```php
use Wolfcharaa\MessageBus\Attribute\CommandHandler;
use Wolfcharaa\MessageBus\Context\MessageContextInterface;

#[CommandHandler(message: CreateUserMessage::class)]
final class CreateUserAction
{
    public function __invoke(CreateUserMessage $message, MessageContextInterface $context): CreateUserResult
    {
        return new CreateUserResult(10);
    }
}
```

Handler всегда принимает два аргумента:

- `CreateUserMessage $message` - входные данные.
- `MessageContextInterface $context` - context текущего выполнения.

Context можно не использовать сразу, но он нужен для вложенного `dispatch()`, `publish()` и доступа к metadata envelope.

### 3. Подготовьте PSR-11 container

MessageBus не создаёт handlers сам. Он просит ваш container вернуть handler по имени класса.

Пример с PHP-DI:


```php
use DI\ContainerBuilder;
use Psr\Container\ContainerInterface;

$container = (new ContainerBuilder())
    ->useAutowiring(true)
    ->build();

assert($container instanceof ContainerInterface);
```

В Symfony, Laravel, Spiral, Yii и других framework-ах обычно используется container самого framework.

### 4. Соберите registry

Registry - это карта “какое сообщение каким handler-ом обрабатывается”.

MessageBus не сканирует весь проект на каждый `dispatch()`. Вместо этого на старте приложения один раз собирается registry:

- какие классы являются messages;
- какие classes являются handlers;
- какой handler обрабатывает какой message;
- какой method надо вызвать;
- какой flow используется: sync или async;
- какой `bindingId` у handler-а;
- какой `MessageAlias` используется для сериализации async message;
- какие middleware подключены к flow или конкретному binding;
- какие cache/retry настройки заданы через attributes.

Registry нужен, чтобы во время выполнения MessageBus работал быстро и предсказуемо. Когда вы вызываете:

```php
$bus->dispatch(new CreateUserMessage('user@example.com'));
```

MessageBus не ищет handler reflection-ом заново. Он берёт из registry готовую запись:

```text
CreateUserMessage -> CreateUserAction::__invoke()
```

После этого он просит PSR-11 container вернуть `CreateUserAction` и вызывает нужный method.

Почему registry собирается явно:

- Ошибки в attributes находятся на старте, а не в production во время обработки запроса.
- Можно проверить, что command имеет primary handler.
- Можно проверить, что async messages имеют стабильный alias.
- Можно проверить, что async handlers имеют стабильный `bindingId`.
- Можно заранее собрать registry в PHP-файл и не использовать reflection в production.
- Framework integration становится проще: container отвечает за services, registry отвечает за message routing.

В dev/test registry можно собрать прямо из списка классов:

```php
use Wolfcharaa\MessageBus\Discovery\ClassListProvider;
use Wolfcharaa\MessageBus\Registry\CompiledMessageRegistry;
use Wolfcharaa\MessageBus\Registry\MessageRegistryCompiler;

$definition = (new MessageRegistryCompiler())->compile(
    new ClassListProvider([
        CreateUserMessage::class,
        CreateUserAction::class,
    ]),
);

$registry = new CompiledMessageRegistry($definition);
```

`ClassListProvider` в примере получает список классов явно. В реальном проекте этот список обычно формируется вашим framework bootstrap-ом, composer classmap-ом или собственной discovery-логикой.

Важно: handler всё равно создаётся container-ом. Registry не заменяет DI container и не хранит готовые объекты. Registry хранит только metadata о том, что и как нужно вызвать.

В production registry лучше заранее сохранить в PHP-файл. Это описано ниже в разделе `Registry`.

### 5. Создайте MessageBus

```php
use Wolfcharaa\MessageBus\MessageBus;

$bus = new MessageBus(
    registry: $registry,
    flows: $definition->flows,
    container: $container,
);
```

### 6. Выполните command

```php
$result = $bus->dispatch(new CreateUserMessage('user@example.com'));

assert($result instanceof CreateUserResult);
echo $result->userId;
```

`dispatch()` выполняет primary sync handler и возвращает бизнес-результат handler-а.

### 7. Что произошло внутри

В этом примере библиотека сделала такие шаги:

- Нашла binding для `CreateUserMessage`.
- Получила `CreateUserAction` из PSR-11 container.
- Создала envelope с `messageId`, `correlationId`, `createdAt` и headers.
- Запустила middleware pipeline.
- Вызвала handler.
- Вернула `CreateUserResult`.

## Event quick start

Event нужен, когда действие уже произошло, а несколько независимых handlers могут на него подписаться.

Например, пользователь создан. После этого можно независимо:

- отправить welcome email;
- записать audit log;
- синхронизировать данные с CRM;
- пересчитать аналитику;
- запустить долгую background задачу.

Event handler не должен быть частью основного command handler-а, если его можно выполнить отдельно или позже.

```php
use Wolfcharaa\MessageBus\Attribute\EventSubscriber;
use Wolfcharaa\MessageBus\Attribute\MessageAlias;

#[MessageAlias('user.created')]
final class UserCreatedEvent
{
    public function __construct(public readonly int $userId) {}
}

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

Async message обязан иметь `MessageAlias`, а async handler обязан иметь стабильный `bindingId`.

### Зачем нужен MessageAlias

`MessageAlias` - это стабильное публичное имя message.

Для sync dispatch библиотека может работать с PHP class name напрямую:

```text
App\Message\UserCreatedEvent
```

Но async message попадает в очередь и хранится там как serialized envelope. Очередь может жить дольше текущего deploy-а. Class name в коде можно переименовать, перенести в другой namespace или заменить во время refactoring-а.

Если в очереди хранить только PHP class name, старые задачи могут перестать десериализоваться после изменения кода.

Поэтому для async messages используется alias:

```php
#[MessageAlias('user.created')]
final class UserCreatedEvent
{
    public function __construct(public readonly int $userId) {}
}
```

В serialized envelope будет сохранено стабильное имя:

```json
{
  "message": {
    "name": "user.created",
    "contentType": "application/json",
    "payload": "{\"userId\":10}",
    "payloadEncoding": "plain"
  }
}
```

За что отвечает `MessageAlias`:

- стабильное имя message в очереди;
- безопасная десериализация после refactoring-а PHP class name;
- интеграция с внешними producers/consumers;
- переносимость envelope между процессами;
- читаемые сообщения в базе/логах/diagnostics.

Практическое правило: если message может попасть в очередь, добавьте ему `MessageAlias`.

### Зачем нужен bindingId

`bindingId` - это стабильный идентификатор конкретной подписки handler-а на message.

Один event может иметь несколько subscribers:

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

Для MessageBus это две разные async задачи. Им нужен стабильный id, чтобы понимать не только “какой event произошёл”, но и “какая именно подписка должна быть выполнена”.

За что отвечает `bindingId`:

- какая именно async подписка будет выполнена worker-ом;
- какой handler надо вызвать после чтения задачи из очереди;
- какой retry policy применяется к этой подписке;
- какой cache/logging/middleware metadata относится к этому binding;
- какой queue job показывать frontend при polling-е;
- какую задачу можно cancel/retry/recover;
- как не потерять задачу после переименования handler class или method.

Почему нельзя полагаться только на class name handler-а:

- handler можно переименовать;
- handler можно разделить на несколько классов;
- один action class может иметь несколько methods и несколько bindings;
- разные handlers одного event-а должны иметь разные retry/status lifecycle;
- очередь хранит задачу дольше одного request-а и иногда дольше одного deploy-а.

Практическое правило: `bindingId` должен быть человекочитаемым и стабильным. Хороший формат:

```text
<message>.<action>
```

Примеры:

```text
user.created.send_welcome_email
user.created.write_audit_log
report.requested.build_pdf
order.paid.sync_crm
```

Для sync event можно выполнить:

```php
$result = $bus->publish(new UserCreatedEvent(10));
```

`publish()` возвращает `PublishResult`, где видно, какие bindings были выполнены, поставлены в очередь или завершились ошибкой.

## Payload serialization

Serialization нужна не для обычного sync `dispatch()`. Sync handler получает PHP object напрямую.

Serialization нужна там, где message пересекает границу процесса или времени:

- async queue;
- worker после другого deploy-а;
- retry отложенной задачи;
- внешние producers/consumers;
- сохранение envelope в PostgreSQL;
- диагностика serialized jobs.

MessageBus разделяет три уровня:

- `MessageSerializerInterface` превращает PHP message object в `SerializedMessage`.
- `EnvelopeSerializerInterface` превращает `Envelope` в `SerializedEnvelope`.
- Queue storage сохраняет `SerializedEnvelope` в backend, например PostgreSQL.

### SerializedMessage

`SerializedMessage` хранит не PHP object, а переносимое представление message:

```php
new SerializedMessage(
    name: 'user.created',
    contentType: 'application/json',
    payload: '{"userId":10}',
    headers: [],
    payloadEncoding: SerializedMessage::PAYLOAD_ENCODING_PLAIN,
);
```

Поля:

- `name` - стабильное имя message, обычно из `MessageAlias`.
- `contentType` - формат payload.
- `payload` - строка с данными.
- `headers` - metadata serializer-а.
- `payloadEncoding` - как payload строка положена в envelope.

Важно: `contentType` и `payloadEncoding` отвечают за разные вещи.

`contentType` говорит, как интерпретировать payload:

- `application/json`;
- `application/vnd.php.serialized`;
- `application/x-protobuf`;
- любой custom media type.

`payloadEncoding` говорит, как payload физически записан в envelope:

- `plain` - payload уже безопасная строка.
- `base64` - payload был binary и перед сохранением закодирован в base64.

### JSON serializer по умолчанию

`JsonMessageSerializer` используется по умолчанию.

Он подходит, когда message payload должен быть переносимым:

- между PHP process-ами;
- между разными версиями приложения;
- между backend и внешними consumers;
- между разными языками программирования.

JSON serializer хранит payload как `application/json`.

```json
{
  "message": {
    "name": "user.created",
    "contentType": "application/json",
    "payload": "{\"userId\":10}",
    "payloadEncoding": "plain"
  }
}
```

Ограничение JSON serializer-а: message должен раскладываться в простые данные.

Подходят:

- `string`;
- `int`;
- `float`;
- `bool`;
- `array`;
- `null`;
- простые DTO, которые можно восстановить через constructor.

Не подходят без custom serializer-а:

- `DateTimeImmutable` как object property;
- enum object property;
- value objects без ручного преобразования;
- resources;
- closures;
- binary data.

Практическое правило: если message может уйти за пределы PHP-приложения, начинайте с JSON.

### PHP serialize serializer

Если проект PHP-only и нужно сохранить richer PHP object graph, используйте `PhpSerializeMessageSerializer`.

```php
use Wolfcharaa\MessageBus\Envelope\DefaultEnvelopeSerializer;
use Wolfcharaa\MessageBus\Serialization\PhpSerializeMessageSerializer;

$messageSerializer = new PhpSerializeMessageSerializer(
    $registry,
    allowedClasses: true,
);

$envelopeSerializer = new DefaultEnvelopeSerializer($messageSerializer);
```

После этого serializer можно передать в runtime:

```php
$runtime = MessageBusRuntime::postgres(
    pdo: $pdo,
    registry: $registry,
    container: $container,
    flows: $flows,
    envelopeSerializer: $envelopeSerializer,
);
```

`PhpSerializeMessageSerializer` хранит payload как `application/vnd.php.serialized`.

Плюсы:

- сохраняет PHP value objects;
- сохраняет `DateTimeImmutable`;
- сохраняет enum properties;
- удобен для PHP-only monolith/service;
- не требует писать mapping для каждого DTO.

Минусы:

- payload понятен только PHP;
- class names становятся частью serialized payload;
- refactoring class structure требует аккуратности;
- нельзя безопасно принимать такой payload от недоверенных внешних producers.

Для безопасности можно ограничить allowed classes:

```php
$messageSerializer = new PhpSerializeMessageSerializer(
    $registry,
    allowedClasses: [
        App\Message\CreateOrder::class,
        App\Message\OrderPaidEvent::class,
        App\ValueObject\OrderId::class,
    ],
);
```

Практическое правило: `allowedClasses: true` допустим внутри доверенного приложения. Для публичных boundaries лучше использовать allow-list или JSON/custom serializer.

### Protobuf и binary payload

Библиотека не добавляет built-in protobuf serializer, потому что protobuf schema, generated classes и mapping в каждом проекте свои.

Но библиотека не блокирует protobuf. Нужно реализовать свой `MessageSerializerInterface`.

Идея serializer-а:

```php
use Wolfcharaa\MessageBus\Serialization\MessageSerializerInterface;
use Wolfcharaa\MessageBus\Serialization\SerializedMessage;

final class ProtobufMessageSerializer implements MessageSerializerInterface
{
    public function serialize(object $message): SerializedMessage
    {
        $binary = $message->serializeToString();

        return new SerializedMessage(
            name: $this->names->nameOf($message),
            contentType: 'application/x-protobuf',
            payload: base64_encode($binary),
            payloadEncoding: SerializedMessage::PAYLOAD_ENCODING_BASE64,
        );
    }

    public function deserialize(SerializedMessage $message): object
    {
        $binary = base64_decode($message->payload, true);

        if ($binary === false) {
            throw new InvalidArgumentException('Invalid protobuf payload encoding.');
        }

        $class = $this->names->classOf($message->name);
        $object = new $class();
        $object->mergeFromString($binary);

        return $object;
    }
}
```

Почему нужен `base64`: serialized envelope хранится как JSON/document-like структура, а raw binary небезопасно класть прямо в JSON string.

### Как выбрать serializer

Используйте JSON, если:

- payload должен быть читаемым;
- возможны внешние consumers;
- важна переносимость между языками;
- message DTO простые;
- вы хотите меньше рисков при refactoring-е PHP classes.

Используйте PHP serialize, если:

- приложение полностью PHP-only;
- очередь не читается внешними consumers;
- payload содержит PHP value objects;
- вы контролируете producers и consumers;
- скорость разработки важнее cross-language переносимости.

Используйте custom serializer, если:

- нужен protobuf;
- нужен Avro/MessagePack/другой формат;
- есть legacy payload format;
- нужно сохранить строгую backward-compatible wire schema;
- message class не совпадает один-в-один с wire payload.

### Result serialization отдельно

Message payload serialization и cache result serialization - разные вещи.

Для queued messages используются:

- `JsonMessageSerializer`;
- `PhpSerializeMessageSerializer`;
- custom `MessageSerializerInterface`.

Для `MessageCacheMiddleware` используются:

- `JsonResultSerializer`;
- `PhpSerializeResultSerializer`;
- custom `ResultSerializerInterface`.

Это разделение нужно, потому что message и handler result имеют разные lifecycle и разные требования к совместимости.

## Async queue quick path

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

### 1. Flows

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

### 2. Пометьте handler как async subscriber

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

### 3. Создайте PostgreSQL runtime

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

### 4. Создайте таблицу очереди

Команда печатает SQL schema для PostgreSQL:

```bash
vendor/bin/message-bus queue:schema:postgres --table=message_bus__queue_jobs
```

Можно сразу записать SQL в файл миграции:

```bash
vendor/bin/message-bus queue:schema:postgres \
  --table=message_bus__queue_jobs \
  --output=database/migrations/message_bus_queue.sql
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

### 5. Опубликуйте event

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

### 6. Сделайте endpoint для polling статуса

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

### 7. Сделайте cancel endpoint, если frontend должен уметь отменять задачи

Для pending задачи можно отменить выполнение:

```php
$runtime->queueControl()?->cancel($queueMessageId);
```

Для running задачи можно запросить отмену:

```php
$runtime->queueControl()?->requestCancellation($queueMessageId);
```

Важно: `requestCancellation()` только ставит флаг. Длинный handler должен сам периодически проверять cancellation state через вашу application-level dependency или context adapter, если ему нужна cooperative cancellation.

### 8. Подготовьте bootstrap для worker

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

### 9. Запустите worker

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
  --max-messages=100 \
  --stop-when-empty
```

Auto mode через `pcntl` master/child processes:

```bash
vendor/bin/message-bus worker:run \
  --bootstrap=config/message_bus_runtime.php \
  --mode=auto \
  --workers=4
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

### 10. Как MessageAlias и bindingId участвуют в async очереди

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

### 11. Как временно выполнить async синхронно

Для debug/local разработки можно принудительно выполнить async bindings в текущем процессе:

```bash
MESSAGE_BUS_FORCE_SYNC=1
```

В этом режиме задачи не попадут в queue provider, а `PublishResult` будет содержать executions с sync mode. Это удобно для отладки handler-а без worker-а и PostgreSQL очереди.

## Core concepts

Это краткая сводка правил, по которым проектируется приложение на MessageBus.

### Message

Message описывает намерение или факт, но не содержит бизнес-логику.

Правила:

- Message должен быть небольшим immutable DTO.
- Message не должен зависеть от container, database connection, logger или framework request.
- Для JSON serializer payload должен состоять из `scalar`, `array`, `null` и простых DTO.
- Для PHP-only payload можно использовать `PhpSerializeMessageSerializer`.
- Для binary/custom payload можно использовать custom serializer и `payloadEncoding: base64`.

Практический смысл: message должен быть безопасно передаваем между process boundary, HTTP request, CLI command и worker.

### Handler binding

Binding - это связь message с конкретным handler method.

Binding отвечает за:

- message class;
- handler class;
- handler method;
- flow;
- `bindingId`;
- primary flag для command/query;
- middleware;
- retry/cache metadata.

Для sync command/query binding может быть почти невидимым. Для async handler `bindingId` становится обязательной публичной идентичностью задачи.

### Handler

Handler выполняет бизнес-операцию.

Обязательная форма:

```php
public function __invoke(Message $message, MessageContextInterface $context): mixed
```

Правила:

- Handler должен быть service в PSR-11 container.
- Handler dependencies передаются через constructor container-ом.
- Handler method принимает только message и context.
- Handler может вернуть result для command/query.
- Event handler обычно возвращает `void`.
- Handler может вызывать nested `dispatch()` или `publish()` через context.

### Envelope

Envelope - это message плюс metadata выполнения.

Envelope содержит:

- `messageId` - id конкретного message.
- `correlationId` - id всей цепочки связанных сообщений.
- `causationId` - id message, который породил текущий message.
- `flow` - выбранный execution flow.
- `bindingId` - конкретный handler binding.
- `createdAt` - время создания envelope.
- `headers` - application metadata.

Практический смысл:

- `messageId` нужен для точечной диагностики.
- `correlationId` связывает request, nested dispatch, events и queue jobs.
- `causationId` показывает причинно-следственную связь.
- `headers` позволяют передать tenant, locale, auth subject id, cache hints или tracing metadata.

### Flow

Flow определяет не “что выполнить”, а “как выполнить”.

Flow управляет:

- sync или async режимом;
- context interface и context factory;
- execution strategy;
- middleware chain;
- transport и queue для async;
- delivery options.

Рекомендация:

- `default` держать sync flow для command/query.
- Для разных классов async нагрузки заводить отдельные flows: `emails`, `reports`, `integrations`.
- Не смешивать долгие heavy jobs и быстрые notification jobs в одной queue, если им нужны разные worker limits.

### Registry

Registry - это compiled metadata графа сообщений и handlers.

Registry не создаёт services и не заменяет container. Он отвечает только на вопросы:

- какие bindings есть у message;
- какой binding является primary;
- какой alias соответствует message class;
- какой class соответствует alias;
- какие flows и middleware участвуют в выполнении.

Production правило: registry лучше компилировать заранее и грузить из PHP-файла.

```php
(new CompiledRegistryFileWriter())->write(
    $definition,
    __DIR__ . '/var/cache/message_bus_registry.php',
);

$registry = CompiledMessageRegistry::fromFile(__DIR__ . '/var/cache/message_bus_registry.php');
```

Если нужно dev/prod поведение в одном месте, используйте `RegistryRuntimeLoader`.

### Serialization

Serialization используется там, где message/result пересекает boundary:

- async queue;
- cache result;
- внешние producers/consumers;
- long-running worker после deploy-а.

Built-in serializers:

- `JsonMessageSerializer` - дефолт для переносимого JSON payload.
- `PhpSerializeMessageSerializer` - PHP-only message payload.
- `JsonResultSerializer` - дефолт для cache result.
- `PhpSerializeResultSerializer` - PHP-only cache result.

Правило выбора:

- Если payload должен быть понятен не только PHP, используйте JSON или custom serializer.
- Если payload строго внутри PHP приложения и содержит value objects, можно использовать PHP serialize.
- Если payload binary, храните его как base64 и задавайте свой `contentType`.

### PublishResult

`dispatch()` возвращает business result одного primary sync handler-а.

`publish()` возвращает технический результат публикации:

- какие bindings выполнены sync;
- какие bindings поставлены в очередь;
- какие enqueue/execution failures произошли;
- какие `queueMessageId` можно вернуть frontend.

Для event fan-out это принципиально: один event может породить несколько независимых executions.

### Queue lifecycle

Async queue job проходит состояния:

- `pending` - ждёт worker-а.
- `running` - выполняется worker-ом.
- `succeeded` - успешно выполнена.
- `failed` - завершилась ошибкой без дальнейших retry.
- `cancelled` - отменена.

Retry работает на уровне конкретного queue job и конкретного `bindingId`. Если один subscriber упал, это не означает, что остальные subscribers того же event-а тоже упали.

### Debug force sync

Для local/debug можно выполнить async bindings синхронно:

```bash
MESSAGE_BUS_FORCE_SYNC=1
```

Это не production mode. Он нужен, чтобы проверить handler logic без worker-а и очереди.

## Container contract

`MessageBus` использует PSR-11 container для:

- handlers/actions;
- middleware;
- context factories;
- execution strategies;
- optional infrastructure fallback.

Явные constructor arguments имеют приоритет над container services.

Fallback lookup order:

| Role | FQCN/interface id | Alias |
| --- | --- | --- |
| Queue provider | `QueueProviderInterface::class` | `message_bus.queue_provider` |
| Envelope serializer | `EnvelopeSerializerInterface::class` | `message_bus.envelope_serializer` |
| Invoker | `CallableInvokerInterface::class` | `message_bus.invoker` |
| Message id generator | `MessageIdGenerator::class` | `message_bus.message_id_generator` |
| Clock | `ClockInterface::class` | `message_bus.clock` |
| Retry policy registry | `RetryPolicyRegistryInterface::class` | `message_bus.retry_policy_registry` |

Container lookup errors:

- `ContainerServiceNotFound`
- `ContainerServiceInvalid`

Сообщения содержат service ids, role, bindingId, flow и expected type, если они применимы.

## Queue and worker

Этот раздел описывает queue contracts. Он нужен, если вы используете встроенный PostgreSQL adapter глубже, пишете свой transport adapter или интегрируете библиотеку с framework worker.

Queue слой разделён на несколько ролей:

- `QueueProviderInterface` - producer-side запись задач.
- `MessageConsumerInterface` - worker-side чтение и lifecycle задач.
- `QueueWorkerInterface` - выполнение одного serialized envelope.
- `QueueWorkerRunner` - loop, который связывает consumer и worker.
- `QueueStatusRepositoryInterface` - чтение статуса задач для API/polling.
- `QueueJobControlInterface` - cancel/request cancellation.

### Producer side: QueueProviderInterface

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

### Batch producer: BatchQueueProviderInterface

Если transport умеет пачечную запись, он может реализовать batch interface:

```php
interface BatchQueueProviderInterface extends QueueProviderInterface
{
    public function enqueueMany(iterable $messages): QueueBatchEnqueueResult;
}
```

MessageBus использует batch provider для `publishMany()` и fan-out событий, где один publish может создать несколько queue jobs.

Практическое правило: если backend поддерживает transaction/batch insert, adapter должен реализовать `BatchQueueProviderInterface`, чтобы не получать частично записанные fan-out задачи без явной ошибки.

### Consumer side: MessageConsumerInterface

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

### ConsumerOptions

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

### QueueWorkerInterface

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

### QueueWorkerRunner

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

### Retry behavior

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

### Status and polling

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

### Job control

Для управления задачами используется `QueueJobControlInterface`:

```php
interface QueueJobControlInterface
{
    public function cancel(string $queueMessageId): void;
    public function requestCancellation(string $queueMessageId): void;
}
```

Разница:

- `cancel()` отменяет pending задачу.
- `requestCancellation()` просит running задачу остановиться.

Running handler не прерывается магически. Если нужна cooperative cancellation, handler должен периодически проверять флаг отмены через application service.

### PostgreSQL adapter

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

### Когда писать свой queue adapter

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

## Cache result

Handler result можно кешировать через `#[CacheResult]` и `MessageCacheMiddleware`.

```php
#[CacheResult(ttlSeconds: 60, identityKey: 'user-report', varyHeaders: ['tenant'])]
#[QueryHandler(message: BuildReportQuery::class, bindingId: 'report.build')]
final class BuildReportHandler
{
    public function __invoke(BuildReportQuery $query, MessageContextInterface $context): ReportDto
    {
        // expensive query
    }
}
```

Подробный пример: [CacheResult middleware](docs/examples/07-cache-result.md).

По умолчанию cache result использует JSON serializer. Для PHP-only result DTO можно подключить PHP serializer:

```php
use Wolfcharaa\MessageBus\Cache\PhpSerializeResultSerializer;
use Wolfcharaa\MessageBus\Middleware\MessageCacheMiddleware;

$middleware = new MessageCacheMiddleware(
    cache: $cache,
    policies: $policyRegistry,
    serializer: new PhpSerializeResultSerializer(allowedClasses: true),
);
```

Если надо не кешировать часть результатов, реализуйте `CacheResultPolicyInterface` и передайте его в middleware.

## Logging middleware

Core middleware пишет структурные события через PSR-3 logger и не логирует payload сообщения.

```php
use Wolfcharaa\MessageBus\Middleware\LoggingMiddleware;
use Wolfcharaa\MessageBus\Middleware\LoggingMiddlewareMode;

$middleware = new LoggingMiddleware(
    logger: $logger,
    mode: LoggingMiddlewareMode::FailuresOnly,
);
```

Доступные режимы:

- `FailuresOnly` - только ошибки, режим по умолчанию.
- `StartedAndFailed` - старт и ошибки.
- `StartedFinishedAndFailed` - старт, успешное завершение и ошибки.

## Framework integration

### Generic PSR-11

```php
$container->set(MessageBusInterface::class, fn (ContainerInterface $c) => new MessageBus(
    registry: $c->get(CompiledMessageRegistry::class),
    flows: $c->get(FlowRegistry::class),
    container: $c,
));
```

Полный пример: [Generic PSR-11](docs/examples/frameworks/generic-psr11.md).

### Symfony

```php
$services->set(MessageBusInterface::class, MessageBus::class)
    ->arg('$registry', service(CompiledMessageRegistry::class))
    ->arg('$flows', service(FlowRegistry::class))
    ->arg('$container', service('service_container'));
```

Полный пример: [Symfony DI](docs/examples/frameworks/symfony-di.md).

### Laravel

```php
$this->app->singleton(MessageBusInterface::class, fn ($app) => new MessageBus(
    registry: $app->make(CompiledMessageRegistry::class),
    flows: $app->make(FlowRegistry::class),
    container: $app,
));
```

Полный пример: [Laravel](docs/examples/frameworks/laravel.md).

### Spiral

```php
$container->bindSingleton(MessageBusInterface::class, fn (Container $container) => new MessageBus(
    registry: $container->get(CompiledMessageRegistry::class),
    flows: $container->get(FlowRegistry::class),
    container: $container,
));
```

Полный пример: [Spiral](docs/examples/frameworks/spiral.md).

### Yii3

```php
MessageBusInterface::class => static fn (ContainerInterface $container) => new MessageBus(
    registry: $container->get(CompiledMessageRegistry::class),
    flows: $container->get(FlowRegistry::class),
    container: $container,
),
```

Полный пример: [Yii3](docs/examples/frameworks/yii3.md).

## Examples index

- [Sync command/query](docs/examples/01-basic-sync.md)
- [Compiled registry for production](docs/examples/02-compiled-registry.md)
- [Async queue and worker](docs/examples/03-async-queue-worker.md)
- [Custom context](docs/examples/04-custom-context.md)
- [Middleware](docs/examples/05-middleware.md)
- [PostgreSQL runtime and CLI](docs/examples/06-postgres-runtime-cli.md)
- [CacheResult middleware](docs/examples/07-cache-result.md)
- [Generic PSR-11](docs/examples/frameworks/generic-psr11.md)
- [Symfony DI](docs/examples/frameworks/symfony-di.md)
- [Spiral](docs/examples/frameworks/spiral.md)
- [Yii3](docs/examples/frameworks/yii3.md)
- [Laravel](docs/examples/frameworks/laravel.md)
- [Analytic report orchestration](docs/examples/analytic-report.md)
- [Cake Queue worker adapter](docs/examples/cake-worker.md)
- [Event fan-out and saga](docs/examples/event-fanout-saga.md)
- [Multi-binding action](docs/examples/multi-binding-action.md)
- [Migration v4 -> v5](docs/migration/v4-to-v5.md)

## Tests

```bash
composer test
```

PostgreSQL integration tests are opt-in:

```bash
MESSAGE_BUS_TEST_PGSQL_DSN='pgsql:host=127.0.0.1;port=55432;dbname=postgres' \
MESSAGE_BUS_TEST_PGSQL_USER=postgres \
MESSAGE_BUS_TEST_PGSQL_PASSWORD=postgres \
vendor/bin/phpunit tests/PostgresQueueIntegrationTest.php
```
