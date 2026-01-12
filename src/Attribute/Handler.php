<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Attribute;

use Attribute;
use Wolfcharaa\MessageBus\Middleware\Middleware;

/**
 * @template TMiddleware of class-string<Middleware>
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class Handler
{
    /** @var array<TMiddleware> */
    public readonly array $middleware;

    public readonly string $group;

    /**
     * @param string $group
     * @param TMiddleware $middleware
     */
    public function __construct(string $group, string ...$middleware)
    {
        $this->group = $group;
        $this->middleware = $middleware;
    }
}
