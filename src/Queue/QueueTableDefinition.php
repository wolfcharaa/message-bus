<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Queue;

final class QueueTableDefinition
{
    public function __construct(
        public readonly string $tableName = 'message_bus__queue_jobs',
    ) {
    }
}
