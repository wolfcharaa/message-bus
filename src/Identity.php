<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Identity;

use Wolfcharaa\MessageBus\Internal\Runtime\OpaqueIdentity;

/**
 *  Stable identity shared by all deliveries produced by one dispatch.
 *
 * @api application
 */
final readonly class MessageId
{
    use OpaqueIdentity;
}

/**
 *  Stable identity of one independently tracked durable target delivery.
 *
 * @api application
 */
final readonly class DeliveryId
{
    use OpaqueIdentity;
}

/**
 *  Stable identity of one claimed execution attempt.
 *
 * @api application
 */
final readonly class AttemptId
{
    use OpaqueIdentity;
}

/**
 *  Correlates a top-level dispatch and all causally nested messages.
 *
 * @api application
 */
final readonly class CorrelationId
{
    use OpaqueIdentity;
}

/**
 *  Opaque fenced credential for compare-and-set attempt settlement.
 *
 * @api application
 */
final readonly class LeaseToken
{
    use OpaqueIdentity;
}

/**
 *  Generates unpredictable technical identities without application input.
 *
 * @api spi
 */
interface IdentityGenerator
{
    public function messageId(): MessageId;

    public function deliveryId(): DeliveryId;

    public function attemptId(): AttemptId;

    public function correlationId(): CorrelationId;

    public function leaseToken(): LeaseToken;
}

/**
 *  Cryptographic default identity generator.
 *
 * @api adapter
 */
final class RandomIdentityGenerator implements IdentityGenerator
{
    public function messageId(): MessageId
    {
        return new MessageId($this->value('msg'));
    }

    public function deliveryId(): DeliveryId
    {
        return new DeliveryId($this->value('del'));
    }

    public function attemptId(): AttemptId
    {
        return new AttemptId($this->value('att'));
    }

    public function correlationId(): CorrelationId
    {
        return new CorrelationId($this->value('cor'));
    }

    public function leaseToken(): LeaseToken
    {
        return new LeaseToken($this->value('lease'));
    }

    private function value(string $prefix): string
    {
        return $prefix . ':' . bin2hex(random_bytes(16));
    }
}
