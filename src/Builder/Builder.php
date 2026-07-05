<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Builder;

use Closure;
use InvalidArgumentException;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use ReflectionClass;
use ReflectionException;
use ReflectionFunction;
use ReflectionMethod;
use Wolfcharaa\MessageBus\Handler\CallableHandler;
use Wolfcharaa\MessageBus\Handler\Handler;
use Wolfcharaa\MessageBus\Handler\HandlerWithMiddleware;
use Wolfcharaa\MessageBus\Middleware\Middleware;

use function count;

class Builder implements HandlerBuilderInterface
{
    /**
     * @var array<Middleware> $middleware
     */
    private array $middleware = [];
    private ContainerInterface $container;

    public function __construct(
        ContainerInterface $container
    ) {
        $this->container = $container;
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function withMiddleware(string ...$middleware): HandlerBuilderInterface
    {
        $clone = clone $this;

        foreach ($middleware as $class) {
            if (!\is_a($class, Middleware::class, true)) {
                throw new InvalidArgumentException(\sprintf(
                    'Middleware `%s` is not supported.',
                    $class
                ));
            }

            $clone->middleware[] = $this->container->get($class);
        }

        return $clone;
    }

    public function wrap(Handler $handler): Handler
    {
        return \count($this->middleware) === 0
            ? $handler
            : new HandlerWithMiddleware($handler, $this->middleware);
    }

    /**
     * @param array{0: class-string|string, 1: ?string} $target
     * @throws ReflectionException
     * @throws NotFoundExceptionInterface
     * @throws ContainerExceptionInterface
     */
    public function build(array $target): Handler
    {
        try {
            switch (true) {
                case count($target) === 2:
                    $target = (new ReflectionClass($target[0]))->getMethod($target[1]);
                    break;
                case class_exists($target[0]) && is_callable($target[0], true):
                    $target = (new ReflectionClass($target[0]))->getMethod('__invoke');
                    break;
                case function_exists($target[0]):
                case $target[0] instanceof Closure:
                    $target = new ReflectionFunction($target[0]);
                    break;
                default:
                    throw new InvalidArgumentException(sprintf(
                        'Target `%s` is not supported',
                        gettype($target[0])
                    ));
            }
        } catch (InvalidArgumentException | ReflectionException $e) {
            throw new InvalidArgumentException($e->getMessage());
        }

        $handler = null;
        if ($target instanceof ReflectionMethod) {
            $handler = new CallableHandler(
                $target->getClosure($this->container->get($target->getDeclaringClass()->getName()))
            );
        }

        $handler ??= new CallableHandler($target->getClosure());

        return $this->wrap($handler);
    }
}
