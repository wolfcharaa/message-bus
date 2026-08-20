# Пример 3. Async flow, queue и worker

Async flow создаёт отдельную queue job на каждый binding.
Для async binding обязателен стабильный `bindingId`.

## Message и handler

```php
<?php

declare(strict_types=1);

use Wolfcharaa\MessageBus\Attribute\EventSubscriber;
use Wolfcharaa\MessageBus\Attribute\MessageAlias;
use Wolfcharaa\MessageBus\Context\MessageContextInterface;

#[MessageAlias('user.created')]
final class UserCreatedEvent
{
    public function __construct(
        public readonly int $userId,
    ) {}
}

#[EventSubscriber(
    message: UserCreatedEvent::class,
    flow: 'notifications',
    bindingId: 'user.created.send_welcome_email',
)]
final class SendWelcomeEmailAction
{
    public function __invoke(UserCreatedEvent $message, MessageContextInterface $context): void
    {
        // отправить письмо
    }
}
```

## Flow

```php
<?php

use Wolfcharaa\MessageBus\Flow\FlowDefinition;
use Wolfcharaa\MessageBus\Flow\FlowRegistry;
use Wolfcharaa\MessageBus\Queue\QueueDeliveryOptions;

$flows = new FlowRegistry(
    FlowDefinition::sync('default'),
    FlowDefinition::async('notifications')
        ->transport('postgres', 'notifications')
        ->delivery(new QueueDeliveryOptions(priority: 5, retryPolicy: 'fast')),
);
```

## Queue provider

```php
<?php

use Wolfcharaa\MessageBus\Queue\QueueEnqueueResult;
use Wolfcharaa\MessageBus\Queue\QueueMessage;
use Wolfcharaa\MessageBus\Queue\QueueProviderInterface;

final class DatabaseQueueProvider implements QueueProviderInterface
{
    public function enqueue(QueueMessage $message): QueueEnqueueResult
    {
        // Сохранить:
        // - $message->envelope
        // - $message->transport
        // - $message->queue
        // - $message->bindingId
        // - $message->priority
        // - $message->availableAt

        return new QueueEnqueueResult('db-row-id');
    }
}
```

## Publish

```php
$result = $bus->publish(new UserCreatedEvent(10));
```

`publish()` не возвращает бизнес-результат handler-а.
Он возвращает `PublishResult` со списком `PublishedExecution`, где queued execution содержит `queueMessageId` для polling.

## Worker

```php
<?php

use Wolfcharaa\MessageBus\Queue\MessageBusQueueWorker;

$worker = new MessageBusQueueWorker($bus, $envelopeSerializer);

// $serializedEnvelope читается из вашей очереди.
$worker->handle($serializedEnvelope);
```

Если lifecycle очереди уже управляется CakeJob, Symfony Messenger, Kafka consumer или другим adapter, он может сам делать `ack/retry/reject`, а библиотечный worker только исполняет envelope.
