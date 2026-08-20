<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Tests;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;
use Wolfcharaa\MessageBus\Cache\ArrayCachePolicyRegistry;
use Wolfcharaa\MessageBus\Cache\CachePolicy;
use Wolfcharaa\MessageBus\Cache\CacheResultPolicyInterface;
use Wolfcharaa\MessageBus\Cache\PhpSerializeResultSerializer;
use Wolfcharaa\MessageBus\Context\MessageContextInterface;
use Wolfcharaa\MessageBus\Envelope\Envelope;
use Wolfcharaa\MessageBus\Execution\HandlerExecutionResultInterface;
use Wolfcharaa\MessageBus\Middleware\MessageCacheMiddleware;
use Wolfcharaa\MessageBus\Middleware\PipelineInterface;
use Wolfcharaa\MessageBus\PublishOptions;
use Wolfcharaa\MessageBus\PublishResult;

final class MessageCacheMiddlewarePolicyTest extends TestCase
{
    public function testResultPolicyCanSkipStoringResult(): void
    {
        $cache = new MessageCachePolicyArrayCache();
        $middleware = new MessageCacheMiddleware(
            $cache,
            new ArrayCachePolicyRegistry(['cache.lookup' => new CachePolicy(60, 'skip')]),
            resultPolicy: new MessageCacheSkipResultPolicy(),
        );
        $context = new MessageCachePolicyContext(new Envelope(
            new MessageCachePolicyLookupMessage(['id' => 10]),
            'message-1',
            'correlation-1',
            null,
            'default',
            'cache.lookup',
            new DateTimeImmutable('2026-08-20T10:00:00+00:00'),
        ));

        $first = $middleware($context, new MessageCachePolicyCountingPipeline(new MessageCachePolicyResultDto('first')));
        $second = $middleware($context, new MessageCachePolicyCountingPipeline(new MessageCachePolicyResultDto('second')));

        self::assertSame('first', $first->value);
        self::assertSame('second', $second->value);
        self::assertSame([], $cache->values);
    }

    public function testPhpSerializerCachesObjectResultWithPhpOnlyPayload(): void
    {
        $cache = new MessageCachePolicyArrayCache();
        $middleware = new MessageCacheMiddleware(
            $cache,
            new ArrayCachePolicyRegistry(['cache.lookup' => new CachePolicy(60, 'php-result')]),
            new PhpSerializeResultSerializer(),
        );
        $context = new MessageCachePolicyContext(new Envelope(
            new MessageCachePolicyLookupMessage(['createdAt' => new DateTimeImmutable('2026-08-20T10:00:00+00:00')]),
            'message-1',
            'correlation-1',
            null,
            'default',
            'cache.lookup',
            new DateTimeImmutable('2026-08-20T10:00:00+00:00'),
        ));

        $first = new MessageCachePolicyCountingPipeline(new MessageCachePolicyPhpResultDto('fresh', new DateTimeImmutable('2026-08-20T10:00:00+00:00')));
        $second = new MessageCachePolicyCountingPipeline(new MessageCachePolicyPhpResultDto('stale', new DateTimeImmutable('2026-08-20T10:00:00+00:00')));

        $firstResult = $middleware($context, $first);
        $secondResult = $middleware($context, $second);

        self::assertSame('fresh', $firstResult->value);
        self::assertSame('fresh', $secondResult->value);
        self::assertSame(1, $first->calls);
        self::assertSame(0, $second->calls);
    }
}

final class MessageCacheSkipResultPolicy implements CacheResultPolicyInterface
{
    public function shouldCache(MessageContextInterface $context, CachePolicy $policy, mixed $result): bool
    {
        return false;
    }
}

final class MessageCachePolicyArrayCache implements CacheInterface
{
    /** @var array<string, mixed> */
    public array $values = [];

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    public function set(string $key, mixed $value, \DateInterval|int|null $ttl = null): bool
    {
        $this->values[$key] = $value;

        return true;
    }

    public function delete(string $key): bool
    {
        unset($this->values[$key]);

        return true;
    }

    public function clear(): bool
    {
        $this->values = [];

        return true;
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        foreach ($keys as $key) {
            yield $key => $this->get((string) $key, $default);
        }
    }

    public function setMultiple(iterable $values, \DateInterval|int|null $ttl = null): bool
    {
        foreach ($values as $key => $value) {
            $this->set((string) $key, $value, $ttl);
        }

        return true;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        foreach ($keys as $key) {
            $this->delete((string) $key);
        }

        return true;
    }

    public function has(string $key): bool
    {
        return \array_key_exists($key, $this->values);
    }
}

final class MessageCachePolicyContext implements MessageContextInterface
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
        throw new \LogicException('Not used in cache middleware policy tests.');
    }

    public function dispatchAll(object $message, PublishOptions $options = new PublishOptions()): HandlerExecutionResultInterface
    {
        throw new \LogicException('Not used in cache middleware policy tests.');
    }

    public function publish(object $message, PublishOptions $options = new PublishOptions()): PublishResult
    {
        throw new \LogicException('Not used in cache middleware policy tests.');
    }
}

final class MessageCachePolicyCountingPipeline implements PipelineInterface
{
    public int $calls = 0;

    public function __construct(private readonly mixed $result)
    {
    }

    public function continue(): mixed
    {
        ++$this->calls;

        return $this->result;
    }
}

final class MessageCachePolicyLookupMessage
{
    /** @param array<string, mixed> $criteria */
    public function __construct(public readonly array $criteria)
    {
    }
}

final class MessageCachePolicyResultDto
{
    public function __construct(public readonly string $value)
    {
    }
}

final class MessageCachePolicyPhpResultDto
{
    public function __construct(
        public readonly string $value,
        public readonly DateTimeImmutable $createdAt,
    ) {
    }
}
