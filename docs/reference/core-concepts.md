# Core concepts

## Message

Message описывает намерение или факт, но не содержит бизнес-логику.

Правила:

- Message должен быть небольшим immutable DTO.
- Message не должен зависеть от container, database connection, logger или framework request.
- Для JSON serializer payload должен состоять из `scalar`, `array`, `null` и простых DTO.
- Для PHP-only payload можно использовать `PhpSerializeMessageSerializer`.
- Для binary/custom payload можно использовать custom serializer и `payloadEncoding: base64`.

Практический смысл: message должен быть безопасно передаваем между process boundary, HTTP request, CLI command и worker.

## Handler binding

Binding - это связь message с конкретным handler method.

Binding отвечает за:

- message class;
- handler class;
- handler method;
- flow;
- `bindingId`;
- primary flag для command/query;
- middleware;
- retry/cache metadata.

Для sync command/query binding может быть почти невидимым. Для async handler `bindingId` становится обязательной публичной идентичностью задачи.

## Handler

Handler выполняет бизнес-операцию.

Обязательная форма:

```php
public function __invoke(Message $message, MessageContextInterface $context): mixed
```

Правила:

- Handler должен быть service в PSR-11 container.
- Handler dependencies передаются через constructor container-ом.
- Handler method принимает только message и context.
- Handler может вернуть result для command/query.
- Event handler обычно возвращает `void`.
- Handler может вызывать nested `dispatch()` или `publish()` через context.

## Envelope

Envelope - это message плюс metadata выполнения.

Envelope содержит:

- `messageId` - id конкретного message.
- `correlationId` - id всей цепочки связанных сообщений.
- `causationId` - id message, который породил текущий message.
- `flow` - выбранный execution flow.
- `bindingId` - конкретный handler binding.
- `createdAt` - время создания envelope.
- `headers` - application metadata.

Практический смысл:

- `messageId` нужен для точечной диагностики.
- `correlationId` связывает request, nested dispatch, events и queue jobs.
- `causationId` показывает причинно-следственную связь.
- `headers` позволяют передать tenant, locale, auth subject id, cache hints или tracing metadata.

## Flow

Flow определяет не “что выполнить”, а “как выполнить”.

Flow управляет:

- sync или async режимом;
- context interface и context factory;
- execution strategy;
- middleware chain;
- transport и queue для async;
- delivery options.

Рекомендация:

- `default` держать sync flow для command/query.
- Для разных классов async нагрузки заводить отдельные flows: `emails`, `reports`, `integrations`.
- Не смешивать долгие heavy jobs и быстрые notification jobs в одной queue, если им нужны разные worker limits.

## Registry

Registry - это compiled metadata графа сообщений и handlers.

Registry не создаёт services и не заменяет container. Он отвечает только на вопросы:

- какие bindings есть у message;
- какой binding является primary;
- какой alias соответствует message class;
- какой class соответствует alias;
- какие flows и middleware участвуют в выполнении.

Production правило: registry лучше компилировать заранее и грузить из PHP-файла.

```php
(new CompiledRegistryFileWriter())->write(
    $definition,
    __DIR__ . '/var/cache/message_bus_registry.php',
);

$registry = CompiledMessageRegistry::fromFile(__DIR__ . '/var/cache/message_bus_registry.php');
```

Если нужно dev/prod поведение в одном месте, используйте `RegistryRuntimeLoader`.

## Serialization

Serialization используется там, где message/result пересекает boundary:

- async queue;
- cache result;
- внешние producers/consumers;
- long-running worker после deploy-а.

Built-in serializers:

- `JsonMessageSerializer` - дефолт для переносимого JSON payload.
- `PhpSerializeMessageSerializer` - PHP-only message payload.
- `JsonResultSerializer` - дефолт для cache result.
- `PhpSerializeResultSerializer` - PHP-only cache result.

Правило выбора:

- Если payload должен быть понятен не только PHP, используйте JSON или custom serializer.
- Если payload строго внутри PHP приложения и содержит value objects, можно использовать PHP serialize.
- Если payload binary, храните его как base64 и задавайте свой `contentType`.

## PublishResult

`dispatch()` возвращает business result одного primary sync handler-а.

`publish()` возвращает технический результат публикации:

- какие bindings выполнены sync;
- какие bindings поставлены в очередь;
- какие enqueue/execution failures произошли;
- какие `queueMessageId` можно вернуть frontend.

Для event fan-out это принципиально: один event может породить несколько независимых executions.

## Queue lifecycle

Async queue job проходит состояния:

- `pending` - ждёт worker-а.
- `running` - выполняется worker-ом.
- `succeeded` - успешно выполнена.
- `failed` - завершилась ошибкой без дальнейших retry.
- `cancelled` - отменена.

Retry работает на уровне конкретного queue job и конкретного `bindingId`. Если один subscriber упал, это не означает, что остальные subscribers того же event-а тоже упали.

## Debug force sync

Для local/debug можно выполнить async bindings синхронно:

```bash
MESSAGE_BUS_FORCE_SYNC=1
```

Это не production mode. Он нужен, чтобы проверить handler logic без worker-а и очереди.
