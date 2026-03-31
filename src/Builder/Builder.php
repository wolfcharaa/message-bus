<?php

declare(strict_types=1);

namespace App\MessageBus\Builder;

use Closure;
use InvalidArgumentException;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use ReflectionClass;
use ReflectionException;
use ReflectionFunction;
use ReflectionMethod;
use App\MessageBus\Handler\CallableHandler;
use App\MessageBus\Handler\Handler;
use App\MessageBus\Handler\HandlerWithMiddleware;
use App\MessageBus\Middleware\Middleware;

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
            if (!is_a($class, Middleware::class, true)) {
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
            switch (true) {
                case count($target) === 2:
                    $target = (new ReflectionClass($target[0]))->getMethod($target[1]);
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

        return \count($this->middleware) === 0
            ? $handler
            : new HandlerWithMiddleware($handler, $this->middleware);
    }
}
