<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Tests;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Wolfcharaa\MessageBus\Attribute\CommandHandler;
use Wolfcharaa\MessageBus\Context\DefaultMessageContextFactory;
use Wolfcharaa\MessageBus\Context\MessageContextInterface;
use Wolfcharaa\MessageBus\Discovery\ClassListProvider;
use Wolfcharaa\MessageBus\Exception\ContainerServiceInvalid;
use Wolfcharaa\MessageBus\Exception\ContainerServiceNotFound;
use Wolfcharaa\MessageBus\Execution\SequentialExecutionStrategy;
use Wolfcharaa\MessageBus\Flow\FlowRegistry;
use Wolfcharaa\MessageBus\MessageBus;
use Wolfcharaa\MessageBus\Registry\CompiledMessageRegistry;
use Wolfcharaa\MessageBus\Registry\MessageRegistryCompiler;
use Wolfcharaa\MessageBus\Tests\Support\TestContainer;

final class ContainerContractV5Test extends TestCase
{
    public function testMissingHandlerServiceThrowsMessageBusContainerExceptionWithBindingContext(): void
    {
        $registry = $this->registry([
            ContainerContractMessage::class,
            ContainerContractAction::class,
        ]);
        $container = $this->baseContainer(autowireClasses: false);
        $bus = new MessageBus(
            registry: $registry,
            flows: $registry->definition()->flows,
            container: $container,
        );

        $this->expectException(ContainerServiceNotFound::class);
        $this->expectExceptionMessage('role `handler`');
        $this->expectExceptionMessage(ContainerContractAction::class);
        $this->expectExceptionMessage('Flow `default`');

        $bus->dispatch(new ContainerContractMessage());
    }

    public function testInvalidHandlerServiceThrowsMessageBusContainerException(): void
    {
        $registry = $this->registry([
            ContainerContractMessage::class,
            ContainerContractAction::class,
        ]);
        $container = $this->baseContainer(autowireClasses: false)
            ->set(ContainerContractAction::class, 'not-an-object');
        $bus = new MessageBus(
            registry: $registry,
            flows: $registry->definition()->flows,
            container: $container,
        );

        $this->expectException(ContainerServiceInvalid::class);
        $this->expectExceptionMessage('role `handler`');
        $this->expectExceptionMessage('Expected `object`, got `string`');

        $bus->dispatch(new ContainerContractMessage());
    }

    public function testMessageBusUsesInfrastructureAliasFallbackFromContainer(): void
    {
        $registry = $this->registry([
            ContainerContractMessage::class,
            ContainerContractAction::class,
        ]);
        $container = $this->baseContainer(autowireClasses: false)
            ->set(ContainerContractAction::class, new ContainerContractAction())
            ->set('message_bus.clock', new ContainerContractFrozenClock());
        $bus = new MessageBus(
            registry: $registry,
            flows: $registry->definition()->flows,
            container: $container,
        );

        self::assertSame('2026-08-20T12:00:00+00:00', $bus->dispatch(new ContainerContractMessage()));
    }

    /** @param list<class-string> $classes */
    private function registry(array $classes): CompiledMessageRegistry
    {
        return new CompiledMessageRegistry((new MessageRegistryCompiler())->compile(
            new ClassListProvider($classes),
            new FlowRegistry(),
            '5.0.0',
            'container-contract-v5-test',
        ));
    }

    private function baseContainer(bool $autowireClasses): TestContainer
    {
        return new TestContainer([
            DefaultMessageContextFactory::class => new DefaultMessageContextFactory(),
            SequentialExecutionStrategy::class => new SequentialExecutionStrategy(),
        ], $autowireClasses);
    }
}

final class ContainerContractMessage
{
}

#[CommandHandler(message: ContainerContractMessage::class)]
final class ContainerContractAction
{
    public function __invoke(ContainerContractMessage $message, MessageContextInterface $context): string
    {
        return $context->envelope()->createdAt->format(DATE_ATOM);
    }
}

final class ContainerContractFrozenClock implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-08-20T12:00:00+00:00');
    }
}
