<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Execution;

use Psr\Clock\ClockInterface;
use Wolfcharaa\MessageBus\Envelope\EnvelopeSerializerInterface;
use Wolfcharaa\MessageBus\Invoker\CallableInvokerInterface;
use Wolfcharaa\MessageBus\Queue\QueueProviderInterface;
use Wolfcharaa\MessageBus\Queue\RetryPolicyRegistryInterface;

final class ExecutionEnvironment
{
    public function __construct(
        public readonly CallableInvokerInterface $invoker,
        public readonly EnvelopeSerializerInterface $envelopeSerializer,
        public readonly ClockInterface $clock,
        public readonly ?QueueProviderInterface $queueProvider = null,
        public readonly ?RetryPolicyRegistryInterface $retryPolicyRegistry = null,
    ) {
    }
}
