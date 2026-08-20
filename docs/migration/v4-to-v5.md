# Миграция с v4 на v5

v5 - новая мажорная версия. Она не пытается сохранять runtime/schema совместимость с v4.

Главное правило миграции: не запускайте v4 producers/workers и v5 producers/workers на одной и той же queue schema, если у вас нет собственной compatibility-прослойки. Встроенную PostgreSQL schema v5 нужно считать новой инфраструктурой.

## Что изменилось на верхнем уровне

- PSR-11 container стал явным integration contract.
- Zero-config/service-resolver подход нужно заменить реальными registrations в container.
- Schema version registry повышена до `5`.
- Compiled registry cache из v4 нужно пересобрать.
- Async events требуют стабильный `MessageAlias`.
- Async handlers требуют стабильный `bindingId`.
- Queue storage использует v5 naming convention `message_bus__...`.
- Queue job lifecycle явно поддерживает status/polling/control сценарии.
- Worker runtime поддерживает `single` и `pcntl auto` режимы.
- Worker control plane добавляет pause/resume/drain/stop/kill/restart/status.
- Handler context поддерживает cooperative cancellation и heartbeat через `CancellableMessageContextInterface` и `HeartbeatAwareMessageContextInterface`.
- Payload serialization стала явной: JSON по умолчанию, PHP serialize для PHP-only payload, custom serializer для protobuf/binary/custom encodings.

## Что нужно изменить в приложении

## 1. Зарегистрируйте PSR-11 container

Все handlers, middleware, context factories и strategies должны быть доступны через container.

```php
$container->set(CreateUserAction::class, fn () => new CreateUserAction($repository));
$container->set(DefaultMessageContextFactory::class, fn () => new DefaultMessageContextFactory());
$container->set(SequentialExecutionStrategy::class, fn () => new SequentialExecutionStrategy());
```

## 2. Пересоберите registry

Не используйте compiled registry cache из v4. v5 использует schema version `5`.

```php
$definition = (new MessageRegistryCompiler())->compile(
    new ClassListProvider($classes),
    $flows,
    '5.0.0',
);
```

## 3. Создайте PostgreSQL schema для v5

Для чистой установки v5:

```bash
vendor/bin/message-bus schema:postgres --with=all
```

Команда генерирует queue и worker-control таблицы:

- `message_bus__queue_jobs`;
- `message_bus__worker_commands`;
- `message_bus__worker_desired_states`;
- `message_bus__worker_instances`;
- `message_bus__worker_child_instances`;
- `message_bus__worker_command_acknowledgements`.

Для существующей v4 installation самый безопасный production-путь:

- остановить или drain-нуть v4 producers;
- дать v4 workers завершить старые jobs;
- архивировать или удалить старые queue tables согласно вашей retention policy;
- применить v5 schema;
- задеплоить v5 producers и workers вместе;
- пересобрать registry cache;
- запустить v5 workers.

Если нужно сохранить pending jobs из v4, напишите application-level migration, которая преобразует старые rows в v5 `SerializedEnvelope` и v5 `bindingId` semantics. Библиотека намеренно не предоставляет автоматическую миграцию данных v4-to-v5.

## 4. Добавьте aliases и binding ids для async работы

```php
#[MessageAlias('user.created')]
final class UserCreatedEvent
{
}

#[EventSubscriber(
    message: UserCreatedEvent::class,
    flow: 'async',
    bindingId: 'user.created.send_welcome_email',
)]
final class SendWelcomeEmail
{
}
```

`MessageAlias` - стабильное serialized name сообщения. `bindingId` - стабильная identity конкретной handler job. Эти значения должны переживать переименование PHP classes.

## 5. Выберите payload serialization

Используйте JSON, если jobs должны быть понятны вне PHP или их нужно удобно инспектировать.

Используйте `PhpSerializeMessageSerializer`, если payload строго PHP-only и содержит value objects, которые не хочется вручную приводить к JSON.

Используйте custom serializer для protobuf/binary форматов и задавайте точный content type, например `application/x-protobuf`.

## 6. Обновите long-running handlers

Для долгих jobs используйте cooperative cancellation и heartbeat:

```php
use Wolfcharaa\MessageBus\Context\CancellableMessageContextInterface;
use Wolfcharaa\MessageBus\Context\HeartbeatAwareMessageContextInterface;

public function __invoke(BuildReport $message, CancellableMessageContextInterface $context): void
{
    foreach ($this->builder->steps($message->reportId) as $step) {
        $context->throwIfCancellationRequested();

        $this->builder->runStep($step);

        if ($context instanceof HeartbeatAwareMessageContextInterface) {
            $context->heartbeat();
        }
    }
}
```

## 7. Обновите worker deployment

Single worker:

```bash
vendor/bin/message-bus worker:run --bootstrap=config/message_bus_runtime.php
```

Auto worker с child processes:

```bash
vendor/bin/message-bus worker:run \
  --bootstrap=config/message_bus_runtime.php \
  --mode=auto \
  --workers=4 \
  --worker-name=emails-worker \
  --worker-group=emails
```

Для supervisor/docker/systemd restart behavior используйте `worker:restart`. Auto runner после graceful drain завершится с настроенным restart exit code.

Это краткая сводка правил, по которым проектируется приложение на MessageBus.

