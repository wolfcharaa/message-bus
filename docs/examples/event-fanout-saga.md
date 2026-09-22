# Event fan-out и saga

`publish()` нужен для событий и side effects. Один event может иметь несколько async subscribers. Если сценарий начинается с command, используйте `CommandHandler` или его семантический alias `SagaHandler`; внутри handler явно вызовите `$context->publish(...)`, когда saga должна породить следующий event.

`dispatch()` не вызывает async event subscribers автоматически. Это оставляет границу между command/saga orchestration и event fan-out явной: retry, status и `bindingId` остаются привязаны к конкретному опубликованному event subscriber.

Если command и async fan-out должны быть единым success-критерием, используйте `dispatchWithFanOut()` из `MessageBusFanOutInterface`. Метод не добавлен в базовый `MessageBusInterface`, поэтому существующие project-specific реализации старого интерфейса не ломаются.

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

## Saga command с обязательным fan-out

```php
use Wolfcharaa\MessageBus\MessageBusFanOutInterface;

final class RegisterIdentityDocumentController
{
    public function __construct(private MessageBusFanOutInterface $bus)
    {
    }

    public function __invoke(int $documentId): void
    {
        $this->bus->dispatchWithFanOut(new RegisterIdentityDocumentCommand($documentId));
    }
}
```

Для этого же `RegisterIdentityDocumentCommand` должны быть зарегистрированы async command bindings:

```php
use Wolfcharaa\MessageBus\Attribute\CommandHandler;

#[CommandHandler(
    message: RegisterIdentityDocumentCommand::class,
    flow: 'async',
    bindingId: 'identity_document.registration.pipeline',
)]
final class ContinueIdentityDocumentRegistration
{
    public function __invoke(RegisterIdentityDocumentCommand $command): void
    {
        // required async continuation
    }
}
```

Если async bindings отсутствуют, `dispatchWithFanOut()` падает до выполнения sync command. Если enqueue async job упал, метод пробрасывает `PublishFailed`: command-side handler уже мог выполниться, но операция не выглядит успешной для вызывающего кода. Для настоящей атомарности с записью бизнес-состояния используйте общий transaction boundary или transactional outbox.
