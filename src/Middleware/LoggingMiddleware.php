<?php

declare(strict_types=1);

namespace App\MessageBus\Middleware;

use Psr\Log\LoggerInterface;
use Throwable;
use App\MessageBus\Message\Context;
use App\MessageBus\Pipeline\Pipeline;

class LoggingMiddleware implements Middleware
{
    private LoggerInterface $logger;

    public function __construct(
        LoggerInterface $logger
    ) {
        $this->logger = $logger;
    }

    /**
     * @throws Throwable
     */
    public function handle(Context $context, Pipeline $pipeline)
    {
        $this->logger->debug('About to handle {message_class}', [
            'message_class' => \get_class($context->envelope->message),
            'envelope' => $context->envelope
        ]);

        try {
            $result = $pipeline->continue();
        } catch (Throwable $exception) {
            $this->logger->error(
                'Failed to handle {message_class}',
                [
                    'exception' => $exception,
                    'message_class' => \get_class($context->envelope->message),
                    'envelope' => $context->envelope
                ]
            );

            throw $exception;
        }

        $this->logger->debug('Successfully handled {message_class}', [
            'message_class' => \get_class($context->envelope->message),
            'envelope' => $context->envelope
        ]);

        return $result;
    }
}
