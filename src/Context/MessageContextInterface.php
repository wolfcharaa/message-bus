<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Context;

use Wolfcharaa\MessageBus\Envelope\Envelope;
use Wolfcharaa\MessageBus\Execution\HandlerExecutionResultInterface;
use Wolfcharaa\MessageBus\Message\Command;
use Wolfcharaa\MessageBus\Message\Query;
use Wolfcharaa\MessageBus\PublishOptions;
use Wolfcharaa\MessageBus\PublishResult;

interface MessageContextInterface
{
    public function envelope(): Envelope;

    /**
     * @template TResult
     * @param Query<TResult>|Command|object $message
     * @return ($message is Query<TResult> ? TResult : void)
     */
    public function dispatch(object $message, PublishOptions $options = new PublishOptions()): mixed;

    public function dispatchAll(object $message, PublishOptions $options = new PublishOptions()): HandlerExecutionResultInterface;

    public function publish(object $message, PublishOptions $options = new PublishOptions()): PublishResult;
}
