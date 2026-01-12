<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Middleware;

use Wolfcharaa\MessageBus\Message\Message;
use Wolfcharaa\MessageBus\Message\Context;
use Wolfcharaa\MessageBus\Pipeline\Pipeline;

interface Middleware
{
    /**
     * @template TResult
     * @template TMessage of Message<TResult>
     * @param Context<TResult, TMessage> $context
     * @param Pipeline<TResult, TMessage> $pipeline
     * @return TResult
     */
    public function handle(Context $context, Pipeline $pipeline): mixed;
}
