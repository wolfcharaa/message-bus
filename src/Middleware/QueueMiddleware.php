<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Middleware;

use Wolfcharaa\MessageBus\Message\Context;
use Wolfcharaa\MessageBus\Pipeline\Pipeline;
use Wolfcharaa\MessageBus\Queue\QueueHeader;
use Wolfcharaa\MessageBus\Queue\QueueProviderInterface;

final class QueueMiddleware implements Middleware
{
    private QueueProviderInterface $queueProvider;

    public function __construct(QueueProviderInterface $queueProvider)
    {
        $this->queueProvider = $queueProvider;
    }

    public function handle(Context $context, Pipeline $pipeline)
    {
        /** @var QueueHeader|null $queueHeader */
        $queueHeader = $context->envelope->header->get(QueueHeader::class);

        if ($queueHeader === null) {
            return $pipeline->continue();
        }

        if ($queueHeader->isStarted === true) {
            return $pipeline->continue();
        }

        $this->queueProvider->enqueue($context->envelope);

        return null;
    }
}
