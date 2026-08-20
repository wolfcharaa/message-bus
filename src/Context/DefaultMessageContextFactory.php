<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Context;

use Wolfcharaa\MessageBus\Envelope\Envelope;
use Wolfcharaa\MessageBus\Flow\FlowDefinition;
use Wolfcharaa\MessageBus\MessageBusInterface;
use Wolfcharaa\MessageBus\Worker\WorkerRuntimeControlInterface;

final class DefaultMessageContextFactory implements MessageContextFactoryInterface
{
    public function create(
        MessageBusInterface $messageBus,
        Envelope $envelope,
        FlowDefinition $flow,
        ?WorkerRuntimeControlInterface $workerRuntimeControl = null,
    ): MessageContextInterface {
        return new DefaultMessageContext($messageBus, $envelope, $workerRuntimeControl);
    }
}
