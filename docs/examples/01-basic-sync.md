# Basic sync command/query

Пример показывает минимальный sync-сценарий. В runtime обязательно нужен PSR-11 container.

```bash
composer require romanfedorskij/message-bus php-di/php-di
```

```php
use DI\ContainerBuilder;
use Wolfcharaa\MessageBus\Attribute\CommandHandler;
use Wolfcharaa\MessageBus\Context\MessageContextInterface;
use Wolfcharaa\MessageBus\Discovery\ClassListProvider;
use Wolfcharaa\MessageBus\MessageBus;
use Wolfcharaa\MessageBus\Registry\CompiledMessageRegistry;
use Wolfcharaa\MessageBus\Registry\MessageRegistryCompiler;

final class CreateUserMessage
{
    public function __construct(public readonly string $email) {}
}

final class CreateUserResult
{
    public function __construct(public readonly int $userId) {}
}

#[CommandHandler(message: CreateUserMessage::class)]
final class CreateUserAction
{
    public function __invoke(CreateUserMessage $message, MessageContextInterface $context): CreateUserResult
    {
        return new CreateUserResult(10);
    }
}

$container = (new ContainerBuilder())
    ->useAutowiring(true)
    ->build();

$definition = (new MessageRegistryCompiler())->compile(
    new ClassListProvider([
        CreateUserMessage::class,
        CreateUserAction::class,
    ]),
);

$registry = new CompiledMessageRegistry($definition);

$bus = new MessageBus(
    registry: $registry,
    flows: $definition->flows,
    container: $container,
);

$result = $bus->dispatch(new CreateUserMessage('user@example.com'));
```
