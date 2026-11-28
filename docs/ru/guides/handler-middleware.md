---
doc_id: guides.handler-middleware
contract_revision: 2
---

# Handler middleware

`HandlerMiddleware<TResult>` оборачивает одну попытку выполнения handler.
Полный порядок цепочки:

```text
global middleware → middleware delivery policy → middleware из #[Handler] → handler
```

Глобальные middleware передаются готовыми process-local объектами:

```php
$middleware = new HandlerMiddlewares(global: [
    new TraceMiddleware($tracer),
]);
```

Middleware частного сценария объявляются непосредственно возле handler. Классы
попадают в compiled `HandlerBinding`, поэтому у каждой Event subscription может
быть собственная цепочка:

```php
final class SendWelcomeHandler
{
    #[Handler(
        bindingId: 'command.send-welcome',
        middleware: [ValidateRecipient::class, AuditWelcome::class],
    )]
    public function __invoke(SendWelcome $command): void
    {
    }
}
```

Каждый указанный класс нужно зарегистрировать в том же
`HandlerServiceResolver`/PSR-11 scope, что и handlers. Контейнер создаёт
middleware при выполнении этого binding. Исключение `get()` выходит без
подмены; `MBR030` на этапе компиляции отклоняет класс, не реализующий
`HandlerMiddleware`.

Durable policy может добавлять переиспользуемые правила attempt:

```php
$policy = new DeliveryPolicy(
    name: 'transactional',
    maxAttempts: 3,
    middleware: [TransactionMiddleware::class],
);
```

Упорядоченные class references policy сохраняются в snapshot delivery. Поэтому
каждая повторная attempt использует принятую цепочку, а сервисы должны оставаться
доступными весь срок жизни delivery. Middleware policy применяются только в
durable worker attempt; Query, Request и inline Event используют global и
handler-local уровни.

Middleware может не вызывать `NextHandler`, выполнив short-circuit, либо вызвать
его ровно один раз. Повторный вызов немедленно завершается ошибкой. Результат и
исключение проходят без изменения, если middleware явно их не преобразует;
cleanup в `finally` остаётся ответственностью middleware. Выход из цепочки
происходит в порядке, обратном входу.

Pipeline оборачивает выполнение handler, а не durable acceptance. Логика,
которая должна выполняться до записи delivery, принадлежит application dispatch
или transaction boundary. Встроенная atomic idempotency boundary остаётся
внешней границей durable effect: при уже завершённом replay она может полностью
пропустить настроенный handler pipeline.

Точные signatures перечислены в [публичном API](../reference/public-api.md); при конфликте source manifest является нормативным.
