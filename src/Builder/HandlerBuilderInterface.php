<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Builder;

use Wolfcharaa\MessageBus\Handler\Handler;

interface HandlerBuilderInterface
{
    public function withMiddleware(string ...$middleware): HandlerBuilderInterface;

    /**
     * @param array{0: class-string|string, 1: ?string} $target
     */
    public function build(array $target): Handler;
}
