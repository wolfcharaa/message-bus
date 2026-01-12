<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Middleware;

use Psr\Log\LoggerInterface;
use Throwable;
use Wolfcharaa\MessageBus\Message\Context;
use Wolfcharaa\MessageBus\Pipeline\Pipeline;

class LoggingMiddleware implements Middleware
{
    public function __construct(
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @throws Throwable
     */
    public function handle(Context $context, Pipeline $pipeline): mixed
    {
        $this->logger->debug('About to handle {message_class}', [
            'message_class' => $context->envelope->message::class,
            'envelope' => $context->envelope
        ]);

        try {
            $result = $pipeline->continue();
        } catch (Throwable $exception) {
            $this->logger->error(
                'Failed to handle {message_class}',
                [
                    'exception' => $exception,
                    'message_class' => $context->envelope->message::class,
                    'envelope' => $context->envelope
                ]
            );

            throw $exception;
        }

        $this->logger->debug('Successfully handled {message_class}', [
            'message_class' => $context->envelope->message::class,
            'envelope' => $context->envelope
        ]);

        return $result;
    }
}
