<?php

declare(strict_types=1);

namespace App\MessageBus\HandlerRegistry;

use App\MessageBus\Builder\HandlerBuilderInterface;
use App\MessageBus\Handler\EventHandlers;
use App\MessageBus\Handler\Handler;
use App\MessageBus\Message\Message;
use App\MessageBus\Middleware\Middleware;

/**
 * Lazy Handler Registry
 * Creates handlers on-demand using factory pattern
 * Handlers are only instantiated when their message is dispatched
 */
final class LazyHandlerRegistry extends HandlerRegistry
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

    /** @var array<string, class-string<Message|object>> $aliases */
    private array $aliases = [];

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
        foreach ($definitions as $definition) {
            $this->messageDefinitions[$definition->getMessageClass()] = $definition;

            if (($alias = $definition->getAlias()) !== null) {
                $this->aliases[$alias] = $definition->getMessageClass();
            }
        }
    }

    public function find(string $messageClass): MessageDefinition
    {
        $alias = $this->aliases[$messageClass] ?? $messageClass;

        // Check if factory exists
        if (!isset($this->messageDefinitions[$alias])) {
            throw new HandlerNotFound($alias);
        }

        // Build handler using factory definition
        $definition = $this->messageDefinitions[$alias];

        if ($definition->getHandler() !== null) {
            return $definition;
        }

        $handlers = $definition->getFactoryHandlers();

        if (\count($handlers) === 0) {
            throw new \LogicException(\sprintf(
                'Message Class `%s` is not set handlers',
                $messageClass
            ));
        }

        $definition->isEvent() === true
            ? $definition->setHandler(new EventHandlers(\array_map(function (array $factory) use ($definition): Handler {
                return $this->buildHandler($definition->getMiddleware(), $factory);
            }, $handlers)))
            : $definition->setHandler($this->buildHandler(
                $definition->getMiddleware(),
                $handlers[0]
            ));

        return $definition;
    }

    /**
     * @param array<class-string<Middleware>> $middleware
     * @param array<class-string, array{0: class-string, 1: string}> $factory
     */
    private function buildHandler(array $middleware, array $factory): Handler
    {
        return $this->builder
            ->withMiddleware(...\array_merge(
                $this->defaultMiddleware,
                $middleware
            ))
            ->build($factory);
    }
}
