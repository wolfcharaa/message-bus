# Analytic report orchestration

Кейс: HTTP action создаёт report run и быстро отдаёт ответ, а долгий расчёт уходит в async worker.

## MessageAlias для стабильного типа отчёта

```php
use Wolfcharaa\MessageBus\Attribute\MessageAlias;

#[MessageAlias('analytic.report.sales')]
final class RunSalesReportMessage
{
    public function __construct(public readonly int $runId) {}
}
```

Alias можно хранить в БД как `import_alias`. PHP-класс можно переименовать, а persisted contract останется стабильным.

## Async orchestration binding

```php
use Wolfcharaa\MessageBus\Attribute\CommandHandler;
use Wolfcharaa\MessageBus\Context\MessageContextInterface;

#[CommandHandler(
    message: ExecuteReportRunMessage::class,
    flow: 'async',
    bindingId: 'analytic.report.execute_run',
)]
final class ExecuteReportRunAction
{
    public function __invoke(ExecuteReportRunMessage $message, MessageContextInterface $context): void
    {
        $run = $this->runs->get($message->runId);
        $messageClass = $this->names->classOf($run->importAlias);

        $context->dispatch(new $messageClass($run->id));

        $this->notifications->reportReady($run->id);
    }
}
```

Что показывает кейс:

- HTTP не держит долгую задачу и память;
- retry повторяет конкретный binding `analytic.report.execute_run`;
- nested dispatch сохраняет `correlationId` и `causationId`;
- `MESSAGE_BUS_FORCE_SYNC=1` позволяет отлаживать async сценарий синхронно.
