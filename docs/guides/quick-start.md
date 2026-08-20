# Quick Start

Этот пример показывает самый короткий путь: создать command, handler, registry и выполнить command синхронно.

## 1. Создайте message

Message - это DTO с данными, которые нужны handler-у.

```php
final class CreateUserMessage
{
    public function __construct(public readonly string $email) {}
}

final class CreateUserResult
{
    public function __construct(public readonly int $userId) {}
}
```

Result - это обычный объект, который вернётся из `dispatch()`.

## 2. Создайте handler

Handler помечается attribute-ом. Так registry понимает, какое сообщение обрабатывает этот класс.

```php
use Wolfcharaa\MessageBus\Attribute\CommandHandler;
use Wolfcharaa\MessageBus\Context\MessageContextInterface;

#[CommandHandler(message: CreateUserMessage::class)]
final class CreateUserAction
{
    public function __invoke(CreateUserMessage $message, MessageContextInterface $context): CreateUserResult
    {
        return new CreateUserResult(10);
    }
}
```

Handler всегда принимает два аргумента:

- `CreateUserMessage $message` - входные данные.
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
$bus->dispatch(new CreateUserMessage('user@example.com'));
```

MessageBus не ищет handler reflection-ом заново. Он берёт из registry готовую запись:

```text
CreateUserMessage -> CreateUserAction::__invoke()
```

После этого он просит PSR-11 container вернуть `CreateUserAction` и вызывает нужный method.

Почему registry собирается явно:

- Ошибки в attributes находятся на старте, а не в production во время обработки запроса.
- Можно проверить, что command имеет primary handler.
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
        CreateUserMessage::class,
        CreateUserAction::class,
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
$result = $bus->dispatch(new CreateUserMessage('user@example.com'));

assert($result instanceof CreateUserResult);
echo $result->userId;
```

`dispatch()` выполняет primary sync handler и возвращает бизнес-результат handler-а.

## 7. Что произошло внутри

В этом примере библиотека сделала такие шаги:

- Нашла binding для `CreateUserMessage`.
- Получила `CreateUserAction` из PSR-11 container.
- Создала envelope с `messageId`, `correlationId`, `createdAt` и headers.
- Запустила middleware pipeline.
- Вызвала handler.
- Вернула `CreateUserResult`.
