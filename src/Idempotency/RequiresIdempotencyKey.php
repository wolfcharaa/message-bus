<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Idempotency;

use Psr\Clock\ClockInterface;
use Wolfcharaa\MessageBus\Clock\WallClock;
use Wolfcharaa\MessageBus\Context\MessageContextInterface;
use Wolfcharaa\MessageBus\Idempotency\Decision\IdempotencyClaimed;
use Wolfcharaa\MessageBus\Idempotency\Decision\IdempotencyCompletedSame;
use Wolfcharaa\MessageBus\Idempotency\Decision\IdempotencyConflict;
use Wolfcharaa\MessageBus\Idempotency\Decision\IdempotencyInProgress;
use Wolfcharaa\MessageBus\Idempotency\Exception\IdempotencyConflictDetected;
use Wolfcharaa\MessageBus\Idempotency\Exception\IdempotencyInProgressException;
use Wolfcharaa\MessageBus\Idempotency\Exception\IdempotencyPolicyMissing;
use Wolfcharaa\MessageBus\Idempotency\Exception\IdempotencyUnsupportedHandlerKind;
use Wolfcharaa\MessageBus\Interceptor\PipelineInterface;
use Wolfcharaa\MessageBus\Registry\HandlerKind;
use Wolfcharaa\MessageBus\Registry\MessageRegistryInterface;
use Wolfcharaa\MessageBus\Serialization\MessageNameResolverInterface;

final readonly class RequiresIdempotencyKey
{
    public function __construct(
        private ResolvedIdempotencyPolicyRegistryInterface $policies,
        private MessageRegistryInterface $registry,
        private ?MessageNameResolverInterface $messageNames = null,
        private ClockInterface $clock = new WallClock(),
    ) {
    }

    public function __invoke(MessageContextInterface $context, PipelineInterface $pipeline): mixed
    {
        $envelope = $context->envelope();
        if ($envelope->bindingId === null) {
            throw IdempotencyPolicyMissing::forBinding(null);
        }

        $binding = $this->registry->binding($envelope->bindingId);
        if ($binding->kind === HandlerKind::Query) {
            throw IdempotencyUnsupportedHandlerKind::forKind($binding->bindingId ?? '', $binding->kind->value);
        }

        $policy = $this->policies->require($envelope->bindingId);
        $execution = new IdempotencyExecution(
            $binding->bindingId ?? '',
            $binding->flow,
            $binding->kind->value,
            $binding->message,
            $this->messageAlias($binding->message),
            $envelope->messageId,
            $envelope->correlationId,
            $envelope->causationId,
            $envelope->createdAt,
        );
        $message = $envelope->message;
        $key = $policy->keyExtractor->service()->extract($message);
        $fingerprint = $policy->fingerprintFactory->service()->fingerprint($message);
        $decision = $policy->store->service()->claim($execution, $key, $fingerprint);

        if ($decision instanceof IdempotencyCompletedSame) {
            return null;
        }

        if ($decision instanceof IdempotencyInProgress) {
            throw IdempotencyInProgressException::forExecution($execution, $key);
        }

        if ($decision instanceof IdempotencyConflict) {
            throw IdempotencyConflictDetected::forExecution($execution, $key);
        }

        if (!$decision instanceof IdempotencyClaimed) {
            throw new \LogicException(\sprintf('Unsupported idempotency decision `%s`.', $decision::class));
        }

        $result = $pipeline->continue();
        $effectReference = $policy->effectReferenceFactory?->service()->effectReference($message, $execution);
        $policy->store->service()->complete(
            $decision->claim,
            new IdempotencyCompletion($this->clock->now(), $effectReference),
        );

        return $result;
    }

    /**
     * @param class-string $messageClass
     */
    private function messageAlias(string $messageClass): ?string
    {
        $name = $this->messageNames?->nameOf($messageClass);

        return $name !== null && $name !== $messageClass ? $name : null;
    }
}
