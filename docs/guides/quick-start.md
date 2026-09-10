# Quick Start

Этот пример показывает самый короткий путь: создать query, handler, registry и выполнить query синхронно.

## 1. Создайте message

Message - это DTO с данными, которые нужны handler-у.

```php
final class CreateUserQuery
{
    public function __construct(public readonly string $email) {}
}

final class CreateUserResult
{
    public function __construct(public readonly int $userId) {}
}
```

Result - это обычный объект, который вернётся из `dispatch()` для query.

## 2. Создайте handler

Handler помечается attribute-ом. Так registry понимает, какое сообщение обрабатывает этот класс.

```php
use Wolfcharaa\MessageBus\Attribute\QueryHandler;
use Wolfcharaa\MessageBus\Context\MessageContextInterface;

#[QueryHandler(message: CreateUserQuery::class)]
final class CreateUserHandler
{
    public function __invoke(CreateUserQuery $message, MessageContextInterface $context): CreateUserResult
    {
        return new CreateUserResult(10);
    }
}
```

Handler всегда принимает два аргумента:

- `CreateUserQuery $message` - входные данные.
- `MessageContextInterface $context` - context текущего выполнения.

Context можно не использовать сразу, но он нужен для вложенного `dispatch()`, `publish()` и доступа к metadata envelope.

## 3. Подготовьте PSR-11 container

MessageBus не создаёт handlers сам. Он просит ваш container вернуть handler по имени класса.

Пример с PHP-DI:


```php
use DI\ContainerBuilder;
use Psr\Container\ContainerInterface;

$container = (new ContainerBuilder())
    ->useAutowiring(true)
    ->build();

assert($container instanceof ContainerInterface);
```

В Symfony, Laravel, Spiral, Yii и других framework-ах обычно используется container самого framework.

## 4. Соберите registry

Registry - это карта “какое сообщение каким handler-ом обрабатывается”.

MessageBus не сканирует весь проект на каждый `dispatch()`. Вместо этого на старте приложения один раз собирается registry:

- какие классы являются messages;
- какие classes являются handlers;
- какой handler обрабатывает какой message;
- какой method надо вызвать;
- какой flow используется: sync или async;
- какой `bindingId` у handler-а;
- какой `MessageAlias` используется для сериализации async message;
- какие middleware подключены к flow или конкретному binding;
- какие cache/retry настройки заданы через attributes.

Registry нужен, чтобы во время выполнения MessageBus работал быстро и предсказуемо. Когда вы вызываете:

```php
$bus->dispatch(new CreateUserQuery('user@example.com'));
```

MessageBus не ищет handler reflection-ом заново. Он берёт из registry готовую запись:

```text
CreateUserQuery -> CreateUserHandler::__invoke()
```

После этого он просит PSR-11 container вернуть `CreateUserHandler` и вызывает нужный method.

Почему registry собирается явно:

- Ошибки в attributes находятся на старте, а не в production во время обработки запроса.
- Можно проверить, что query имеет ровно один sync handler.
- Можно проверить, что command handler возвращает `void`.
- Можно проверить, что async messages имеют стабильный alias.
- Можно проверить, что async handlers имеют стабильный `bindingId`.
- Можно заранее собрать registry в PHP-файл и не использовать reflection в production.
- Framework integration становится проще: container отвечает за services, registry отвечает за message routing.

В dev/test registry можно собрать прямо из списка классов:

```php
use Wolfcharaa\MessageBus\Discovery\ClassListProvider;
use Wolfcharaa\MessageBus\Registry\CompiledMessageRegistry;
use Wolfcharaa\MessageBus\Registry\MessageRegistryCompiler;

$definition = (new MessageRegistryCompiler())->compile(
    new ClassListProvider([
        CreateUserQuery::class,
        CreateUserHandler::class,
    ]),
);

$registry = new CompiledMessageRegistry($definition);
```

`ClassListProvider` в примере получает список классов явно. В реальном проекте этот список обычно формируется вашим framework bootstrap-ом, composer classmap-ом или собственной discovery-логикой.

Важно: handler всё равно создаётся container-ом. Registry не заменяет DI container и не хранит готовые объекты. Registry хранит только metadata о том, что и как нужно вызвать.

В production registry лучше заранее сохранить в PHP-файл. Это описано ниже в разделе `Registry`.

## 5. Создайте MessageBus

```php
use Wolfcharaa\MessageBus\MessageBus;

$bus = new MessageBus(
    registry: $registry,
    flows: $definition->flows,
    container: $container,
);
```

## 6. Выполните command

```php
$result = $bus->dispatch(new CreateUserQuery('user@example.com'));

assert($result instanceof CreateUserResult);
echo $result->userId;
```

`dispatch()` выполняет sync query handler и возвращает его бизнес-результат. Для command используется тот же метод, но command handler обязан возвращать `void`.

## 7. Что произошло внутри

В этом примере библиотека сделала такие шаги:

- Нашла binding для `CreateUserQuery`.
- Получила `CreateUserHandler` из PSR-11 container.
- Создала envelope с `messageId`, `correlationId`, `createdAt` и headers.
- Запустила middleware pipeline.
- Вызвала handler.
- Вернула `CreateUserResult`.
