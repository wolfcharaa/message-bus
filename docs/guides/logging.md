# Logging middleware

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
