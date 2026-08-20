<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Tests;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;
use Wolfcharaa\MessageBus\Context\MessageContextInterface;
use Wolfcharaa\MessageBus\Envelope\Envelope;
use Wolfcharaa\MessageBus\Execution\HandlerExecutionResultInterface;
use Wolfcharaa\MessageBus\Middleware\LoggingMiddleware;
use Wolfcharaa\MessageBus\Middleware\LoggingMiddlewareMode;
use Wolfcharaa\MessageBus\Middleware\PipelineInterface;
use Wolfcharaa\MessageBus\PublishOptions;
use Wolfcharaa\MessageBus\PublishResult;

final class LoggingMiddlewareTest extends TestCase
{
    public function testDefaultModeLogsOnlyFailures(): void
    {
        $logger = new LoggingMemoryLogger();
        $middleware = new LoggingMiddleware($logger);
        $context = new LoggingMiddlewareContext(new Envelope(
            new LoggingMiddlewareMessage(),
            'message-1',
            'correlation-1',
            null,
            'default',
            'logging.handle',
            new DateTimeImmutable('2026-08-20T10:00:00+00:00'),
        ));

        $this->expectException(RuntimeException::class);

        try {
            $middleware($context, new LoggingFailingPipeline());
        } finally {
            self::assertCount(1, $logger->records);
            self::assertSame('error', $logger->records[0]['level']);
            self::assertSame('message_bus.handler.failed', $logger->records[0]['message']);
            self::assertSame('message-1', $logger->records[0]['context']['messageId']);
        }
    }

    public function testStartedFinishedModeLogsSuccessLifecycle(): void
    {
        $logger = new LoggingMemoryLogger();
        $middleware = new LoggingMiddleware($logger, LoggingMiddlewareMode::StartedFinishedAndFailed);
        $context = new LoggingMiddlewareContext(new Envelope(
            new LoggingMiddlewareMessage(),
            'message-1',
            'correlation-1',
            null,
            'default',
            'logging.handle',
            new DateTimeImmutable('2026-08-20T10:00:00+00:00'),
        ));

        self::assertSame('ok', $middleware($context, new LoggingSuccessPipeline('ok')));
        self::assertSame(['message_bus.handler.started', 'message_bus.handler.finished'], \array_column($logger->records, 'message'));
    }
}

final class LoggingMemoryLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string|Stringable, context: array<string, mixed>}> */
    public array $records = [];

    /** @param array<string, mixed> $context */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = [
            'level' => (string) $level,
            'message' => $message,
            'context' => $context,
        ];
    }
}

final class LoggingFailingPipeline implements PipelineInterface
{
    public function continue(): mixed
    {
        throw new RuntimeException('boom');
    }
}

final class LoggingSuccessPipeline implements PipelineInterface
{
    public function __construct(private readonly mixed $result)
    {
    }

    public function continue(): mixed
    {
        return $this->result;
    }
}

final class LoggingMiddlewareContext implements MessageContextInterface
{
    public function __construct(private readonly Envelope $envelope)
    {
    }

    public function envelope(): Envelope
    {
        return $this->envelope;
    }

    public function dispatch(object $message, PublishOptions $options = new PublishOptions()): mixed
    {
        throw new \LogicException('Not used in logging middleware tests.');
    }

    public function dispatchAll(object $message, PublishOptions $options = new PublishOptions()): HandlerExecutionResultInterface
    {
        throw new \LogicException('Not used in logging middleware tests.');
    }

    public function publish(object $message, PublishOptions $options = new PublishOptions()): PublishResult
    {
        throw new \LogicException('Not used in logging middleware tests.');
    }
}

final class LoggingMiddlewareMessage
{
}
