<?php

declare(strict_types=1);

namespace App\MessageBus\HandlerRegistry;

use App\MessageBus\Builder\HandlerBuilderInterface;
use App\MessageBus\Handler\EventHandlers;
use App\MessageBus\Handler\Handler;
use App\MessageBus\Message\Message;
use App\MessageBus\Middleware\Middleware;

final class ArrayHandlerRegistry extends HandlerRegistry
{
    private HandlerBuilderInterface $builder;

    /**
     * @var array<class-string<Message|object>, MessageDefinition> $messageDefinitions Handler factory definitions
     */
    private array $messageDefinitions;

    /**
     * @var array<class-string<Middleware>> $defaultMiddleware
     */
    private array $defaultMiddleware;

    /**
     * @param HandlerBuilderInterface $builder
     * @param array<class-string<Middleware>> $defaultMiddleware
     * @param MessageDefinition ...$definitions
     */
    public function __construct(
        HandlerBuilderInterface $builder,
        array $defaultMiddleware = [],
        MessageDefinition ...$definitions
    ) {
        $this->builder = $builder;
        $this->defaultMiddleware = $defaultMiddleware;
        $this->addMessages($definitions);
    }

    public function find(string $messageClass): MessageDefinition
    {
        if (!isset($this->messageDefinitions[$messageClass])) {
            throw new HandlerNotFound($messageClass);
        }

        return $this->messageDefinitions[$messageClass];
    }

    /**
     * @param array<MessageDefinition> $definitions
     */
    public function addMessages(array $definitions): self
    {
        foreach ($definitions as $definition) {
            $this->addMessage($definition);
        }

        return $this;
    }

    public function addMessage(MessageDefinition $definition): self
    {
        $handlers = $definition->getFactoryHandlers();

        if (\count($handlers) === 0) {
            throw new \LogicException(sprintf(
                'Message Class `%s` is not set handlers',
                $definition->getMessageClass()
            ));
        }

        $this->messageDefinitions[$definition->getMessageClass()] = $definition->isEvent() === true
            ? $definition->setHandler(new EventHandlers(array_map(function (array $factory) use ($definition): Handler {
                return $this->buildHandler($definition->getMiddleware(), $factory);
            }, $handlers)))
            : $definition->setHandler($this->buildHandler(
                $definition->getMiddleware(),
                $handlers[0]
            ));

        return $this;
    }

    /**
     * @param array<class-string<Middleware>> $middleware
     * @param array<class-string, array{0: class-string, 1: string}> $factory
     */
    private function buildHandler(array $middleware, array $factory): Handler
    {
        return $this->builder
            ->withMiddleware(...array_merge(
                $this->defaultMiddleware,
                $middleware
            ))
            ->build($factory);
    }
}
