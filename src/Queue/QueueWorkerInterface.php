<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Queue;

use Wolfcharaa\MessageBus\Envelope\SerializedEnvelope;

interface QueueWorkerInterface
{
    public function handle(SerializedEnvelope $envelope): mixed;
}
