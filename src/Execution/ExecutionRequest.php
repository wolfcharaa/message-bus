<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Execution;

use Wolfcharaa\MessageBus\Context\MessageContextInterface;
use Wolfcharaa\MessageBus\Flow\FlowDefinition;
use Wolfcharaa\MessageBus\PublishOptions;
use Wolfcharaa\MessageBus\Registry\HandlerBindingDefinition;

final class ExecutionRequest
{
    /** @var \Closure(HandlerBindingDefinition): MessageContextInterface */
    private readonly \Closure $contextForBinding;

    /**
     * @param non-empty-list<HandlerBindingDefinition> $bindings
     * @param null|callable(HandlerBindingDefinition): MessageContextInterface $contextForBinding
     */
    public function __construct(
        public readonly array $bindings,
        public readonly MessageContextInterface $context,
        public readonly FlowDefinition $flow,
        public readonly PublishOptions $options,
        public readonly ExecutionEnvironment $environment,
        ?callable $contextForBinding = null,
    ) {
        $this->contextForBinding = $contextForBinding !== null
            ? \Closure::fromCallable($contextForBinding)
            : static fn (HandlerBindingDefinition $binding): MessageContextInterface => $context;
    }

    public function contextForBinding(HandlerBindingDefinition $binding): MessageContextInterface
    {
        return ($this->contextForBinding)($binding);
    }
}
