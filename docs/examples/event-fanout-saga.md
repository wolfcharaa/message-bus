# Event fan-out и saga

`publish()` нужен для событий и side effects. Один event может иметь несколько async subscribers.

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
