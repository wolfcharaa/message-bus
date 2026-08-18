<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Execution;

use Wolfcharaa\MessageBus\Context\MessageContextInterface;
use Wolfcharaa\MessageBus\Flow\FlowDefinition;
use Wolfcharaa\MessageBus\PublishOptions;
use Wolfcharaa\MessageBus\Registry\HandlerBindingDefinition;

final class ExecutionRequest
{
    /**
     * @param non-empty-list<HandlerBindingDefinition> $bindings
     */
    public function __construct(
        public readonly array $bindings,
        public readonly MessageContextInterface $context,
        public readonly FlowDefinition $flow,
        public readonly PublishOptions $options,
        public readonly ExecutionEnvironment $environment,
    ) {
    }
}
