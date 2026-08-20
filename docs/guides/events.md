# Event quick start

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

## Зачем нужен MessageAlias

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

## Зачем нужен bindingId

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
