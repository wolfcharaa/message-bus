<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Context;

use Wolfcharaa\MessageBus\Envelope\Envelope;
use Wolfcharaa\MessageBus\Execution\HandlerExecutionResultInterface;
use Wolfcharaa\MessageBus\PublishOptions;

interface MessageContextInterface
{
    public function envelope(): Envelope;

    public function dispatch(object $message, PublishOptions $options = new PublishOptions()): mixed;

    public function dispatchAll(object $message, PublishOptions $options = new PublishOptions()): HandlerExecutionResultInterface;

    public function publish(object $message, PublishOptions $options = new PublishOptions()): void;
}
