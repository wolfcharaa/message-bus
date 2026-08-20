<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Queue;

final class QueueBatchEnqueueResult
{
    /** @var list<QueueEnqueueResult> */
    private array $results;

    public function __construct(QueueEnqueueResult ...$results)
    {
        $this->results = \array_values($results);
    }

    /**
     * @return list<QueueEnqueueResult>
     */
    public function all(): array
    {
        return $this->results;
    }
}
