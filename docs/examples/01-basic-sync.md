# Пример 1. Sync command/query

Минимальная настройка без очереди и без отдельного container.

```php
<?php

declare(strict_types=1);

use Wolfcharaa\MessageBus\Attribute\CommandHandler;
use Wolfcharaa\MessageBus\Context\MessageContextInterface;
use Wolfcharaa\MessageBus\Discovery\ClassListProvider;
use Wolfcharaa\MessageBus\Message\Command;
use Wolfcharaa\MessageBus\MessageBus;
use Wolfcharaa\MessageBus\Registry\CompiledMessageRegistry;
use Wolfcharaa\MessageBus\Registry\MessageRegistryCompiler;

/**
 * @implements Command<CreateUserResult>
 */
final class CreateUserMessage implements Command
{
    public function __construct(
        public readonly string $email,
    ) {}
}

final class CreateUserResult
{
    public function __construct(
        public readonly int $userId,
    ) {}
}

#[CommandHandler(message: CreateUserMessage::class)]
final class CreateUserAction
{
    public function __invoke(CreateUserMessage $message, MessageContextInterface $context): CreateUserResult
    {
        return new CreateUserResult(10);
    }
}

$definition = (new MessageRegistryCompiler())->compile(
    new ClassListProvider([
        CreateUserMessage::class,
        CreateUserAction::class,
    ]),
);

$registry = new CompiledMessageRegistry($definition);
$bus = new MessageBus($registry, $definition->flows);

$result = $bus->dispatch(new CreateUserMessage('user@example.com'));
```

`dispatch()` возвращает результат primary sync handler.

