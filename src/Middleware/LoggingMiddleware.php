<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Middleware;

use Psr\Log\LoggerInterface;
use Throwable;
use Wolfcharaa\MessageBus\Context\MessageContextInterface;

final class LoggingMiddleware
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly LoggingMiddlewareMode $mode = LoggingMiddlewareMode::FailuresOnly,
    ) {
    }

    public function __invoke(MessageContextInterface $context, PipelineInterface $pipeline): mixed
    {
        if ($this->mode !== LoggingMiddlewareMode::FailuresOnly) {
            $this->logger->info('message_bus.handler.started', $this->context($context));
        }

        try {
            $result = $pipeline->continue();
        } catch (Throwable $e) {
            $this->logger->error('message_bus.handler.failed', $this->context($context) + [
                'exception' => $e,
                'exceptionClass' => $e::class,
                'exceptionMessage' => $e->getMessage(),
            ]);

            throw $e;
        }

        if ($this->mode === LoggingMiddlewareMode::StartedFinishedAndFailed) {
            $this->logger->info('message_bus.handler.finished', $this->context($context));
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function context(MessageContextInterface $context): array
    {
        $envelope = $context->envelope();

        return [
            'messageClass' => $envelope->message::class,
            'messageId' => $envelope->messageId,
            'correlationId' => $envelope->correlationId,
            'causationId' => $envelope->causationId,
            'flow' => $envelope->flow,
            'bindingId' => $envelope->bindingId,
        ];
    }
}
