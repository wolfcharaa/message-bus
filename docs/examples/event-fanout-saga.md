# Event fan-out и saga

`publish()` нужен для событий и side effects. Один event может иметь несколько async subscribers. Если сценарий начинается с command, используйте `CommandHandler` или его семантический alias `SagaHandler`; внутри handler явно вызовите `$context->publish(...)`, когда saga должна породить следующий event.

`dispatch()` не вызывает async event subscribers автоматически. Это оставляет границу между command/saga orchestration и event fan-out явной: retry, status и `bindingId` остаются привязаны к конкретному опубликованному event subscriber.

## Event fan-out

```php
#[MessageAlias('gateway.identity_document.viewed')]
final class IdentityDocumentViewedEvent
{
    public function __construct(public readonly int $documentId) {}
}

#[EventSubscriber(
    message: IdentityDocumentViewedEvent::class,
    flow: 'async',
    bindingId: 'gateway.identity_document.viewed.ensure_individual',
)]
final class EnsureIndividualExistsSaga
{
    public function __invoke(IdentityDocumentViewedEvent $event, MessageContextInterface $context): void
    {
        // ensure individual exists
        $context->publish(new IdentityDocumentRegisteredEvent($event->documentId));
    }
}

#[EventSubscriber(
    message: IdentityDocumentViewedEvent::class,
    flow: 'async',
    bindingId: 'gateway.identity_document.viewed.audit',
)]
final class AuditDocumentViewAction
{
    public function __invoke(IdentityDocumentViewedEvent $event, MessageContextInterface $context): void
    {
        // write audit log
    }
}
```

`publish(new IdentityDocumentViewedEvent(...))` создаст отдельные queue jobs по каждому `bindingId`.

Что показывает кейс:

- event fan-out не является одним неопределённым job;
- retry/reject относится к конкретному subscriber;
- saga может публиковать следующий event;
- `correlationId` сохраняет цепочку событий.

## Command как saga entrypoint

```php
use Wolfcharaa\MessageBus\Attribute\SagaHandler;
use Wolfcharaa\MessageBus\Context\MessageContextInterface;

final class RegisterIdentityDocumentCommand
{
    public function __construct(public readonly int $documentId) {}
}

#[SagaHandler(message: RegisterIdentityDocumentCommand::class)]
final class RegisterIdentityDocumentSaga
{
    public function __invoke(RegisterIdentityDocumentCommand $command, MessageContextInterface $context): void
    {
        // validate and persist command-side state

        $context->publish(new IdentityDocumentRegisteredEvent($command->documentId));
    }
}
```

`SagaHandler` не создаёт новый handler kind и не меняет registry schema. Это тот же command binding, но с названием, которое лучше описывает orchestration-сценарий.
