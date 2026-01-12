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

    public function __construct(
        private readonly ContainerInterface $container,
    ) {
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function withMiddleware(string ...$middleware): HandlerBuilderInterface
    {
        $clone = clone $this;

        foreach ($middleware as $class) {
            if (
                !is_a($class, Middleware::class, true)
            ) {
                throw new InvalidArgumentException(sprintf(
                    'Middleware `%s` is not supported.',
                    $class
                ));
            }

            $clone->middleware[] = $this->container->get($class);
        }

        return $clone;
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
            $target = match (true) {
                count($target) === 2 => (new ReflectionClass($target[0]))->getMethod($target[1]),
                $target[0] instanceof Closure => new ReflectionFunction($target[0]),
                function_exists($target[0]) => new ReflectionFunction($target[0]),
                default => throw new InvalidArgumentException(sprintf(
                    'Target `%s` is not supported',
                    gettype($target[0])
                )),
            };
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

        return empty($handler)
            ? $handler
            : new HandlerWithMiddleware(
                $handler,
                array_map(function (Middleware $middleware) use ($target): Middleware {
                    return $middleware;
                }, $this->middleware),
            );
    }
}
