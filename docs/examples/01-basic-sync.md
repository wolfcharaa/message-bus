# Basic sync query

Пример показывает минимальный sync-сценарий. В runtime обязательно нужен PSR-11 container.

```bash
composer require romanfedorskij/message-bus php-di/php-di
```

```php
use DI\ContainerBuilder;
use Wolfcharaa\MessageBus\Attribute\QueryHandler;
use Wolfcharaa\MessageBus\Context\MessageContextInterface;
use Wolfcharaa\MessageBus\Discovery\ClassListProvider;
use Wolfcharaa\MessageBus\MessageBus;
use Wolfcharaa\MessageBus\Registry\CompiledMessageRegistry;
use Wolfcharaa\MessageBus\Registry\MessageRegistryCompiler;

final class CreateUserQuery
{
    public function __construct(public readonly string $email) {}
}

final class CreateUserResult
{
    public function __construct(public readonly int $userId) {}
}

#[QueryHandler(message: CreateUserQuery::class)]
final class CreateUserHandler
{
    public function __invoke(CreateUserQuery $message, MessageContextInterface $context): CreateUserResult
    {
        return new CreateUserResult(10);
    }
}

$container = (new ContainerBuilder())
    ->useAutowiring(true)
    ->build();

$definition = (new MessageRegistryCompiler())->compile(
    new ClassListProvider([
        CreateUserQuery::class,
        CreateUserHandler::class,
    ]),
);

$registry = new CompiledMessageRegistry($definition);

$bus = new MessageBus(
    registry: $registry,
    flows: $definition->flows,
    container: $container,
);

$result = $bus->dispatch(new CreateUserQuery('user@example.com'));
```
