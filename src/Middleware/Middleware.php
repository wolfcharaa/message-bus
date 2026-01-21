<?php

declare(strict_types=1);

namespace App\MessageBus\Middleware;

use App\MessageBus\Message\Message;
use App\MessageBus\Message\Context;
use App\MessageBus\Pipeline\Pipeline;

interface Middleware
{
    /**
     * @template TResult
     * @template TMessage of Message<TResult>
     * @param Context<TResult, TMessage> $context
     * @param Pipeline<TResult, TMessage> $pipeline
     * @return TResult
     */
    public function handle(Context $context, Pipeline $pipeline);
}
