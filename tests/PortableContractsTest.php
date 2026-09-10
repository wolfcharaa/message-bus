<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Wolfcharaa\MessageBus\Envelope\Headers;
use Wolfcharaa\MessageBus\Postgres\PostgresRetryConfig;
use Wolfcharaa\MessageBus\Postgres\PostgresRetryProfile;
use Wolfcharaa\MessageBus\Queue\QueueDeliveryOptions;
use Wolfcharaa\MessageBus\Queue\RetryDelayStrategy;
use Wolfcharaa\MessageBus\Queue\RetryPolicy;
use Wolfcharaa\MessageBus\Queue\RetryPolicySnapshot;

final class PortableContractsTest extends TestCase
{
    public function testHeadersAreImmutableAndSupportEnumKeys(): void
    {
        $empty = Headers::empty();
        $headers = $empty
            ->with(PortableHeaderKey::Tenant, 'tenant-a')
            ->with('nested', ['region' => 77, 'active' => true, 'empty' => null]);

        self::assertNull($empty->get(PortableHeaderKey::Tenant));
        self::assertSame('tenant-a', $headers->get(PortableHeaderKey::Tenant));
        self::assertSame(['region' => 77, 'active' => true, 'empty' => null], $headers->get('nested'));
        self::assertSame($headers->all(), $headers->jsonSerialize());
    }

    public function testHeadersMergeWithOverride(): void
    {
        $base = new Headers(['tenant' => 'a', 'trace' => '1']);
        $override = new Headers(['tenant' => 'b', 'locale' => 'ru']);

        self::assertSame(
            ['tenant' => 'b', 'trace' => '1', 'locale' => 'ru'],
            $base->merge($override)->all(),
        );
    }

    public function testHeadersRejectNonPortableValues(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Header value must be scalar, array or null.');

        new Headers(['bad' => new \stdClass()]);
    }

    public function testHeadersRejectNonStringKeys(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Header key must be a string.');

        new Headers([10 => 'bad']);
    }

    public function testQueueDeliveryOptionsMergeAndSerialization(): void
    {
        $base = new QueueDeliveryOptions(priority: 1, delaySeconds: 30, retryPolicy: 'default');

        self::assertSame($base, $base->merge(null));

        $merged = $base->merge(new QueueDeliveryOptions(priority: 5));
        self::assertSame(5, $merged->priority);
        self::assertSame(30, $merged->delaySeconds);
        self::assertSame('default', $merged->retryPolicy);

        self::assertSame([
            'priority' => 5,
            'delaySeconds' => 30,
            'retryPolicy' => 'default',
        ], $merged->toArray());
        self::assertEquals($merged, QueueDeliveryOptions::fromArray($merged->toArray()));
        self::assertNull(QueueDeliveryOptions::fromArray(null));
    }

    public function testRetryPolicySnapshotFromKnownPolicies(): void
    {
        self::assertSame([
            'maxAttempts' => 3,
            'strategy' => 'exponential',
            'parameters' => [
                'initialDelaySeconds' => 30,
                'multiplier' => 2.0,
                'maxDelaySeconds' => 300,
            ],
        ], RetryPolicySnapshot::default()->toArray());

        self::assertSame([
            'maxAttempts' => 4,
            'strategy' => 'fixed',
            'parameters' => ['delaySeconds' => 9],
        ], RetryPolicySnapshot::fromPolicy(RetryPolicy::fixed(4, 9))->toArray());

        self::assertSame([
            'maxAttempts' => 5,
            'strategy' => 'exponential',
            'parameters' => [
                'initialDelaySeconds' => 2,
                'multiplier' => 3.0,
                'maxDelaySeconds' => 20,
            ],
        ], RetryPolicySnapshot::fromPolicy(RetryPolicy::exponential(5, 2, 3.0, 20))->toArray());
    }

    public function testRetryPolicySnapshotPreservesCustomStrategyClass(): void
    {
        $snapshot = RetryPolicySnapshot::fromPolicy(new RetryPolicy(2, new PortableCustomRetryDelayStrategy()));

        self::assertSame(2, $snapshot->maxAttempts);
        self::assertSame(PortableCustomRetryDelayStrategy::class, $snapshot->strategy);
        self::assertSame([], $snapshot->parameters);
    }

    public function testPostgresRetryConfigProfilesAndArrayOverrides(): void
    {
        $fast = PostgresRetryConfig::fromProfile(PostgresRetryProfile::Fast);
        self::assertSame(2, $fast->attempts);
        self::assertSame(50, $fast->initialDelayMilliseconds);
        self::assertSame(250, $fast->maxDelayMilliseconds);

        $disabled = PostgresRetryConfig::disabled();
        self::assertFalse($disabled->enabled);

        $config = PostgresRetryConfig::fromArray([
            'profile' => 'patient',
            'enabled' => false,
            'attempts' => 7,
            'initial_delay_ms' => 10,
            'multiplier' => 3,
            'max_delay_ms' => 100,
            'jitter' => false,
        ]);

        self::assertFalse($config->enabled);
        self::assertSame(7, $config->attempts);
        self::assertSame(10, $config->initialDelayMilliseconds);
        self::assertSame(3.0, $config->multiplier);
        self::assertSame(100, $config->maxDelayMilliseconds);
        self::assertFalse($config->jitter);
        self::assertSame(0, $config->delayMillisecondsForRetry(0));
        self::assertSame(10, $config->delayMillisecondsForRetry(1));
        self::assertSame(30, $config->delayMillisecondsForRetry(2));
        self::assertSame(100, $config->delayMillisecondsForRetry(4));
    }

    public function testPostgresRetryConfigValidatesInput(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('PostgreSQL retry attempts must be greater than zero.');

        new PostgresRetryConfig(attempts: 0);
    }

    public function testPostgresRetryConfigValidatesDelayInput(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('PostgreSQL retry delays must not be negative.');

        new PostgresRetryConfig(initialDelayMilliseconds: -1);
    }

    public function testPostgresRetryConfigValidatesMultiplierInput(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('PostgreSQL retry multiplier must be greater than or equal to 1.0.');

        new PostgresRetryConfig(multiplier: 0.5);
    }
}

enum PortableHeaderKey: string
{
    case Tenant = 'tenant';
}

final class PortableCustomRetryDelayStrategy implements RetryDelayStrategy
{
    public function delaySeconds(int $attempt): int
    {
        return $attempt;
    }
}
