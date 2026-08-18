# MessageBus

`romanfedorskij/message-bus` — PHP 8.3+ библиотека для явной обработки команд, запросов и событий через attributes, flows, compiled registry и переносимый queue payload без `serialize()`.

## Требования

- PHP `^8.3`
- `psr/container`
- `psr/clock`
- `symfony/var-exporter`
- `ext-json`

## Установка

```bash
composer require romanfedorskij/message-bus
```

## Примеры

Подробные сценарии подключения вынесены в `docs/examples`:

- [Sync command/query](docs/examples/01-basic-sync.md)
- [Compiled registry для production](docs/examples/02-compiled-registry.md)
- [Async queue и worker](docs/examples/03-async-queue-worker.md)
- [Custom context](docs/examples/04-custom-context.md)
- [Middleware](docs/examples/05-middleware.md)

## Основная идея

В v4 регистрация строится не как ручной список `MessageDefinition`, а как индекс binding-ов:

```text
message -> bindings -> flow -> pipeline -> action
```

Action объявляет связь с message через attribute:

```php
use Wolfcharaa\MessageBus\Attribute\CommandHandler;
use Wolfcharaa\MessageBus\Context\MessageContextInterface;

final class CreateUserMessage
{
    public function __construct(
        public readonly string $email,
    ) {}
}

final class CreateUserResult
{
    public function __construct(
        public readonly int $userId,
    ) {}
}

#[CommandHandler(message: CreateUserMessage::class)]
final class CreateUserAction
{
    public function __invoke(CreateUserMessage $message, MessageContextInterface $context): CreateUserResult
    {
        return new CreateUserResult(123);
    }
}
```

Handler всегда принимает два аргумента:

```php
public function __invoke(Message $message, MessageContextInterface $context): mixed
```

`Context` передаётся всегда, но использовать его внутри action не обязательно.

## Attributes

Для разных сценариев есть разные attributes:

```php
#[CommandHandler(message: CreateUserMessage::class)]
final class CreateUserAction {}

#[QueryHandler(message: FindUserMessage::class)]
final class FindUserAction {}

#[EventSubscriber(message: UserCreatedEvent::class, flow: 'notifications', bindingId: 'user.created.email')]
final class SendWelcomeEmailAction {}
```

`CommandHandler`, `QueryHandler`, `EventSubscriber` внутри приводятся к единому `HandlerBindingDefinition`, поэтому discovery остаётся простым, а публичный API читается явно.

## MessageAlias

Alias не живёт на handler. Alias относится к message contract:

```php
use Wolfcharaa\MessageBus\Attribute\MessageAlias;

#[MessageAlias('user.created')]
final class UserCreatedEvent
{
    public function __construct(
        public readonly int $userId,
    ) {}
}
```

Правила:

- sync-only message может быть без alias;
- async/transport message обязан иметь alias;
- alias должен быть уникальным;
- если message попадает в queue payload, alias обязателен.
- compiler читает alias с message-класса по binding, поэтому class provider может находить только action-классы, если message-классы автозагружаются.

## Flow

Flow описывает context, strategy, middleware и transport.

```php
use Wolfcharaa\MessageBus\Flow\FlowDefinition;
use Wolfcharaa\MessageBus\Flow\FlowRegistry;
use Wolfcharaa\MessageBus\Queue\QueueDeliveryOptions;

$flows = new FlowRegistry(
    FlowDefinition::sync('default'),

    FlowDefinition::async('notifications')
        ->transport('database', 'notifications')
        ->delivery(new QueueDeliveryOptions(priority: 0, retryPolicy: 'fast-notification'))
);
```

Если flows не переданы, библиотека создаёт default sync flow:

```php
FlowDefinition::sync('default')
```

Async flows всегда объявляются явно.

## Context

Для простых случаев используется базовый `MessageContextInterface`.

Для кастомного flow можно подменить context через factory:

```php
use Wolfcharaa\MessageBus\Context\MessageContextFactoryInterface;

FlowDefinition::sync('reports')
    ->context(ReportContextInterface::class, ReportContextFactory::class);

final class ReportContextFactory implements MessageContextFactoryInterface
{
    public function create(MessageBusInterface $bus, Envelope $envelope, FlowDefinition $flow): MessageContextInterface
    {
        return new ReportContext($bus, $envelope);
    }
}
```

Context immutable. Middleware не может подменить context в середине pipeline.

## Discovery и compiled registry

Для dev/test можно собрать registry runtime reflection-ом:

```php
use Wolfcharaa\MessageBus\Discovery\ClassListProvider;
use Wolfcharaa\MessageBus\Registry\MessageRegistryCompiler;
use Wolfcharaa\MessageBus\Registry\CompiledMessageRegistry;

$definition = (new MessageRegistryCompiler())->compile(
    new ClassListProvider([
        CreateUserMessage::class,
        CreateUserAction::class,
    ]),
    $flows,
    libraryVersion: '4.0.0',
);

$registry = new CompiledMessageRegistry($definition);
```

Для production рекомендуется compiled PHP artifact:

```php
use Wolfcharaa\MessageBus\Dumper\SymfonyVarExporterRegistryDumper;

$php = (new SymfonyVarExporterRegistryDumper())->dump($definition);
file_put_contents(__DIR__ . '/var/cache/message_bus_registry.php', $php);
```

Runtime загрузка:

```php
$registry = CompiledMessageRegistry::fromFile(__DIR__ . '/var/cache/message_bus_registry.php');
```

Artifact содержит:

- `schemaVersion`
- `libraryVersion`
- `generatedAt`
- `sourceHash`
- `flows`
- `messages`
- `bindings`
- `aliases`
- `messageNames`

Loader падает, если `schemaVersion` не поддерживается.

## Class providers

Библиотека не требует готовый список классов. Можно комбинировать providers:

```php
use Wolfcharaa\MessageBus\Discovery\ChainClassProvider;
use Wolfcharaa\MessageBus\Discovery\ComposerClassMapProvider;
use Wolfcharaa\MessageBus\Discovery\Psr4DirectoryClassProvider;

$provider = new ChainClassProvider(
    new ComposerClassMapProvider(
        classMapFile: __DIR__ . '/vendor/composer/autoload_classmap.php',
        namespacePrefixes: ['App\\Feature\\', 'App\\Gateway\\']
    ),
    new Psr4DirectoryClassProvider([
        'App\\Feature\\' => __DIR__ . '/src/Feature',
        'App\\Gateway\\' => __DIR__ . '/src/Gateway',
    ])
);
```

## Dispatch API

```php
$result = $bus->dispatch(new CreateUserMessage('user@example.com'));
```

`dispatch()` выполняет primary sync binding и возвращает основной результат.

```php
$result = $bus->dispatchAll($message);
```

`dispatchAll()` выполняет все sync bindings и возвращает `HandlerExecutionResultInterface`.

```php
$bus->publish(new UserCreatedEvent(123));
```

`publish()` ставит async bindings в очередь и не возвращает бизнес-результат.

Для debug/admin:

```php
$bus->dispatchPublishedSync(new UserCreatedEvent(123));

$bus->dispatchBindingSync(new UserCreatedEvent(123), 'user.created.email');
```

Worker/internal:

```php
$bus->dispatchEnvelopeToBinding($envelope);
```

## Очереди

Async job создаётся по конкретному binding, а не по всему `message + flow`.

```php
#[EventSubscriber(
    message: UserCreatedEvent::class,
    flow: 'notifications',
    bindingId: 'user.created.send_welcome_email'
)]
final class SendWelcomeEmailAction {}
```

Для async binding `bindingId` обязателен. Он должен быть стабильным и не зависеть от FQCN класса.

Delivery settings собираются последовательно:

```text
flow defaults -> binding override -> PublishOptions override
```

Если binding задаёт только `delaySeconds`, он не сбрасывает `priority`, заданный на flow.

Producer side:

```php
use Wolfcharaa\MessageBus\Queue\QueueProviderInterface;
use Wolfcharaa\MessageBus\Queue\QueueMessage;
use Wolfcharaa\MessageBus\Queue\QueueEnqueueResult;

final class DatabaseQueueProvider implements QueueProviderInterface
{
    public function enqueue(QueueMessage $message): QueueEnqueueResult
    {
        // сохранить $message->envelope, transport, queue, bindingId, priority, availableAt

        return new QueueEnqueueResult('queue-message-id');
    }
}
```

Consumer side опционален:

```php
interface MessageConsumerInterface
{
    public function next(ConsumerOptions $options): ?ReceivedQueueMessage;

    public function ack(ReceivedQueueMessage $message): void;

    public function retry(ReceivedQueueMessage $message, Throwable $reason): void;

    public function reject(ReceivedQueueMessage $message, Throwable $reason): void;
}
```

Если lifecycle уже управляется CakeJob, Symfony Messenger или Kafka consumer, adapter может вызывать только:

```php
$worker->handle($serializedEnvelope);
```

## Envelope и headers

Transport передаёт envelope, а не голый message.

Envelope хранит системные поля:

- `messageId`
- `correlationId`
- `causationId`
- `flow`
- `bindingId`
- `createdAt`
- `headers`

Вложенный dispatch сохраняет текущую механику:

```text
root:
messageId = generated
causationId = null
correlationId = messageId

nested:
messageId = generated
causationId = current envelope.messageId
correlationId = current envelope.correlationId
```

Headers используют string keys:

```php
use Wolfcharaa\MessageBus\Envelope\Headers;

enum HeaderKey: string
{
    case RequestId = 'request_id';
    case Actor = 'actor';
}

$headers = Headers::empty()
    ->with(HeaderKey::RequestId, 'request-1')
    ->with(HeaderKey::Actor, ['id' => 10]);
```

Значения headers: только `scalar|array|null`. Объекты не поддерживаются.

## Сериализация

PHP `serialize()` не используется.

Базовый serializer:

```php
JsonMessageSerializer
```

Он поддерживает только переносимые значения:

```text
scalar|array|null
```

Message должен быть простым immutable DTO:

```php
#[MessageAlias('report.build')]
final class BuildReportMessage
{
    public function __construct(
        public readonly int $reportId,
        public readonly string $reportType,
        public readonly string $createdAt,
    ) {}
}
```

Enum и DateTime передаются явными значениями:

```php
public readonly string $reportType;
public readonly string $createdAt;
```

Восстановление enum/date делается внутри handler.

## Validation

Compiler валит registry на этапе discovery/compile, если:

- flow не зарегистрирован;
- async flow без transport;
- async binding без `bindingId`;
- async message без `MessageAlias`;
- duplicate binding id;
- duplicate primary;
- query имеет больше одного handler;
- query handler возвращает `void`;
- handler signature не принимает ожидаемые `message, context`;
- middleware signature не принимает `context, pipeline`;
- payload содержит объекты/resources/closures.

## Тесты

```bash
composer test
```
