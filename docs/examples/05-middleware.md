# Пример 5. Middleware

Middleware подключается на уровне flow и binding.
При выполнении они объединяются в порядке:

```text
flow middleware -> binding middleware -> handler
```

## Middleware class

```php
<?php

declare(strict_types=1);

use Wolfcharaa\MessageBus\Context\MessageContextInterface;
use Wolfcharaa\MessageBus\Middleware\PipelineInterface;

final class AuditMiddleware
{
    public function __invoke(MessageContextInterface $context, PipelineInterface $pipeline): mixed
    {
        // до handler
        $result = $pipeline->continue();
        // после handler

        return $result;
    }
}
```

## Flow middleware

```php
<?php

use Wolfcharaa\MessageBus\Flow\FlowDefinition;

$flow = FlowDefinition::sync('default')
    ->middleware(AuditMiddleware::class);
```

## Binding middleware

```php
<?php

use Wolfcharaa\MessageBus\Attribute\CommandHandler;

#[CommandHandler(
    message: CreateReportMessage::class,
    middleware: [ValidateReportAccessMiddleware::class],
)]
final class CreateReportAction
{
    public function __invoke(CreateReportMessage $message, ReportContextInterface $context): CreateReportResult
    {
        // ...
    }
}
```

Compiler проверит, что middleware принимает context interface выбранного flow и `PipelineInterface`.

