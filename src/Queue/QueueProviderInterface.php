<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Queue;

use Wolfcharaa\MessageBus\Envelope;

interface QueueProviderInterface
{
    public function enqueue(Envelope $envelope): void;
}
