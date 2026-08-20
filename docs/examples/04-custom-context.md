# Пример 4. Custom context для отдельного flow

Custom context нужен, когда handler-ам конкретного flow требуется больше возможностей, чем базовые `dispatch`, `dispatchAll`, `publish` и `envelope`.

## Context interface

```php
<?php

declare(strict_types=1);

use Wolfcharaa\MessageBus\Context\MessageContextInterface;

interface ReportContextInterface extends MessageContextInterface
{
    public function actorId(): int;
}
```

## Context implementation

```php
<?php

use Wolfcharaa\MessageBus\Context\DefaultMessageContext;
use Wolfcharaa\MessageBus\Envelope\Envelope;
use Wolfcharaa\MessageBus\Execution\HandlerExecutionResultInterface;
use Wolfcharaa\MessageBus\MessageBusInterface;
use Wolfcharaa\MessageBus\PublishOptions;
use Wolfcharaa\MessageBus\PublishResult;

final class ReportContext implements ReportContextInterface
{
    private readonly DefaultMessageContext $inner;

    public function __construct(
        MessageBusInterface $bus,
        Envelope $envelope,
        private readonly int $actorId,
    ) {
        $this->inner = new DefaultMessageContext($bus, $envelope);
    }

    public function actorId(): int
    {
        return $this->actorId;
    }

    public function envelope(): Envelope
    {
        return $this->inner->envelope();
    }

    public function dispatch(object $message, PublishOptions $options = new PublishOptions()): mixed
    {
        return $this->inner->dispatch($message, $options);
    }

    public function dispatchAll(object $message, PublishOptions $options = new PublishOptions()): HandlerExecutionResultInterface
    {
        return $this->inner->dispatchAll($message, $options);
    }

    public function publish(object $message, PublishOptions $options = new PublishOptions()): PublishResult
    {
        return $this->inner->publish($message, $options);
    }
}
```

## Factory

```php
<?php

use Wolfcharaa\MessageBus\Context\MessageContextFactoryInterface;
use Wolfcharaa\MessageBus\Context\MessageContextInterface;
use Wolfcharaa\MessageBus\Envelope\Envelope;
use Wolfcharaa\MessageBus\Flow\FlowDefinition;
use Wolfcharaa\MessageBus\MessageBusInterface;

final class ReportContextFactory implements MessageContextFactoryInterface
{
    public function create(MessageBusInterface $bus, Envelope $envelope, FlowDefinition $flow): MessageContextInterface
    {
        $actorId = (int) ($envelope->headers->get('actor_id') ?? 0);

        return new ReportContext($bus, $envelope, $actorId);
    }
}
```

## Flow

```php
<?php

use Wolfcharaa\MessageBus\Flow\FlowDefinition;

$flow = FlowDefinition::sync('reports')
    ->context(ReportContextInterface::class, ReportContextFactory::class);
```

Handler этого flow может требовать `ReportContextInterface` вторым аргументом.
