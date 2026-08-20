<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Tests;

use DateInterval;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;
use Wolfcharaa\MessageBus\Cache\ArrayCachePolicyRegistry;
use Wolfcharaa\MessageBus\Cache\CachePolicy;
use Wolfcharaa\MessageBus\Context\MessageContextInterface;
use Wolfcharaa\MessageBus\Envelope\Envelope;
use Wolfcharaa\MessageBus\Envelope\Headers;
use Wolfcharaa\MessageBus\Execution\HandlerExecutionResultInterface;
use Wolfcharaa\MessageBus\Middleware\MessageCacheMiddleware;
use Wolfcharaa\MessageBus\Middleware\PipelineInterface;
use Wolfcharaa\MessageBus\PublishOptions;
use Wolfcharaa\MessageBus\PublishResult;

final class MessageCacheMiddlewareTest extends TestCase
{
    public function testCacheMissStoresResultAndHitSkipsPipeline(): void
    {
        $cache = new MessageCacheArrayCache();
        $middleware = new MessageCacheMiddleware(
            $cache,
            new ArrayCachePolicyRegistry([
                'cache.lookup' => new CachePolicy(60, 'user-1'),
            ]),
        );
        $context = $this->context(new MessageCacheLookupMessage(['b' => 2, 'a' => 1]));
        $first = new MessageCacheCountingPipeline(new MessageCacheResultDto('fresh'));
        $second = new MessageCacheCountingPipeline(new MessageCacheResultDto('stale'));

        $firstResult = $middleware($context, $first);
        $secondResult = $middleware($context, $second);

        self::assertInstanceOf(MessageCacheResultDto::class, $firstResult);
        self::assertInstanceOf(MessageCacheResultDto::class, $secondResult);
        self::assertSame('fresh', $firstResult->value);
        self::assertSame('fresh', $secondResult->value);
        self::assertSame(1, $first->calls);
        self::assertSame(0, $second->calls);
        self::assertCount(1, $cache->values);
    }

    public function testVaryHeadersCreateDifferentCacheEntries(): void
    {
        $cache = new MessageCacheArrayCache();
        $middleware = new MessageCacheMiddleware(
            $cache,
            new ArrayCachePolicyRegistry([
                'cache.lookup' => new CachePolicy(60, 'user-1', ['tenant']),
            ]),
        );
        $tenantA = $this->context(new MessageCacheLookupMessage(['id' => 10]), Headers::empty()->with('tenant', 'a'));
        $tenantB = $this->context(new MessageCacheLookupMessage(['id' => 10]), Headers::empty()->with('tenant', 'b'));
        $firstA = new MessageCacheCountingPipeline(new MessageCacheResultDto('tenant-a'));
        $firstB = new MessageCacheCountingPipeline(new MessageCacheResultDto('tenant-b'));
        $secondA = new MessageCacheCountingPipeline(new MessageCacheResultDto('tenant-a-stale'));

        $resultA = $middleware($tenantA, $firstA);
        $resultB = $middleware($tenantB, $firstB);
        $cachedA = $middleware($tenantA, $secondA);

        self::assertSame('tenant-a', $resultA->value);
        self::assertSame('tenant-b', $resultB->value);
        self::assertSame('tenant-a', $cachedA->value);
        self::assertSame(1, $firstA->calls);
        self::assertSame(1, $firstB->calls);
        self::assertSame(0, $secondA->calls);
        self::assertCount(2, $cache->values);
    }

    public function testDisabledPolicyBypassesCache(): void
    {
        $cache = new MessageCacheArrayCache();
        $middleware = new MessageCacheMiddleware(
            $cache,
            new ArrayCachePolicyRegistry([
                'cache.lookup' => new CachePolicy(60, 'user-1', enabled: false),
            ]),
        );
        $context = $this->context(new MessageCacheLookupMessage(['id' => 10]));
        $first = new MessageCacheCountingPipeline(new MessageCacheResultDto('first'));
        $second = new MessageCacheCountingPipeline(new MessageCacheResultDto('second'));

        $firstResult = $middleware($context, $first);
        $secondResult = $middleware($context, $second);

        self::assertSame('first', $firstResult->value);
        self::assertSame('second', $secondResult->value);
        self::assertSame(1, $first->calls);
        self::assertSame(1, $second->calls);
        self::assertSame([], $cache->values);
    }

    private function context(object $message, ?Headers $headers = null): MessageCacheContext
    {
        return new MessageCacheContext(new Envelope(
            $message,
            'message-1',
            'correlation-1',
            null,
            'default',
            'cache.lookup',
            new DateTimeImmutable('2026-08-20T10:00:00+00:00'),
            $headers ?? Headers::empty(),
        ));
    }
}

final class MessageCacheArrayCache implements CacheInterface
{
    /** @var array<string, mixed> */
    public array $values = [];

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    public function set(string $key, mixed $value, DateInterval|int|null $ttl = null): bool
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
            yield $key => $this->get($key, $default);
        }
    }

    public function setMultiple(iterable $values, DateInterval|int|null $ttl = null): bool
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

final class MessageCacheCountingPipeline implements PipelineInterface
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

final class MessageCacheContext implements MessageContextInterface
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
        throw new \LogicException('Not used in cache middleware tests.');
    }

    public function dispatchAll(object $message, PublishOptions $options = new PublishOptions()): HandlerExecutionResultInterface
    {
        throw new \LogicException('Not used in cache middleware tests.');
    }

    public function publish(object $message, PublishOptions $options = new PublishOptions()): PublishResult
    {
        throw new \LogicException('Not used in cache middleware tests.');
    }
}

final class MessageCacheLookupMessage
{
    /** @param array<string, mixed> $criteria */
    public function __construct(public readonly array $criteria)
    {
    }
}

final class MessageCacheResultDto
{
    public function __construct(public readonly string $value)
    {
    }
}
