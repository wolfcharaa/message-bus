<?php

declare(strict_types=1);

namespace App\MessageBus\Handler;

use App\MessageBus\Message\Context;
use App\MessageBus\Message\Message;

/**
 * @template TResult = mixed
 * @template TMessage of Message<TResult> = Message<mixed>
 */
interface Handler
{
    /**
     * @param Context<TResult, TMessage> $context
     * @return TResult
     */
    public function handle(Context $context);
}
