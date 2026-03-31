<?php

declare(strict_types=1);

namespace App\MessageBus\HandlerRegistry;

use App\MessageBus\Builder\HandlerBuilderInterface;
use App\MessageBus\Handler\EventHandlers;
use App\MessageBus\Handler\Handler;
use App\MessageBus\Message\Event;
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
     * @var array<class-string<Message>, MessageDefinition> $handlerDefinitions Handler factory definitions
     */
    private array $handlerDefinitions;

    /**
     * @var array<class-string, Handler> Cached handler instances
     */
    private array $handlers = [];

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
        foreach ($definitions as $definition) {
            $this->handlerDefinitions[$definition->getMessageClass()] = $definition;
        }
    }

    public function find(string $messageClass): ?Handler
    {
        // Return cached handler if exists
        if (isset($this->handlers[$messageClass])) {
            return $this->handlers[$messageClass];
        }

        // Check if factory exists
        if (!isset($this->handlerDefinitions[$messageClass])) {
            throw new HandlerNotFound($messageClass);
        }

        // Build handler using factory definition
        try {
            $definition = $this->handlerDefinitions[$messageClass];
            $handlers = $definition->getHandlers();

            if (($countHandlers = count($handlers)) === 0) {
                throw new \LogicException(sprintf(
                    'Message Class `%s` is not set handlers',
                    $messageClass
                ));
            }

            if (is_a($messageClass, Event::class, true)) {
                $eventHandlers = [];
                foreach ($handlers as $factoryHandler) {
                    $eventHandlers[] = $this->buildHandler($definition, $factoryHandler);
                }
                $handler = new EventHandlers($eventHandlers);
            } else {
                if ($countHandlers > 1) {
                    throw new \LogicException(sprintf(
                        'This `%s` message has multiple handlers. Message class is not `%s`',
                        $messageClass,
                        Event::class
                    ));
                }

                $handler = $this->buildHandler(
                    $definition,
                    $definition->getHandlers()[0]
                );
            }
        } finally {
            unset($this->handlerDefinitions[$messageClass]);
        }

        // Cache for future use
        $this->handlers[$messageClass] = $handler;

        return $handler;
    }

    /**
     * @param MessageDefinition $definition
     * @param array<class-string, array{0: class-string, 1: string}> $factory
     */
    public function buildHandler(MessageDefinition $definition, array $factory): Handler
    {
        return $this->builder
            ->withMiddleware(...array_merge(
                $this->defaultMiddleware,
                $definition->getMiddleware()
            ))
            ->build($factory);
    }
}
