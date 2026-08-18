<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Context;

use Wolfcharaa\MessageBus\Envelope\Envelope;
use Wolfcharaa\MessageBus\Flow\FlowDefinition;
use Wolfcharaa\MessageBus\MessageBusInterface;

final class DefaultMessageContextFactory implements MessageContextFactoryInterface
{
    public function create(
        MessageBusInterface $messageBus,
        Envelope $envelope,
        FlowDefinition $flow,
    ): MessageContextInterface {
        return new DefaultMessageContext($messageBus, $envelope);
    }
}
