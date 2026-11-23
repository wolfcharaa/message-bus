<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Adapter;

use Psr\Clock\ClockInterface;
use Wolfcharaa\MessageBus\Delivery\CancellationOutcome;
use Wolfcharaa\MessageBus\Delivery\CancellationReceipt;
use Wolfcharaa\MessageBus\Delivery\ClaimedDelivery;
use Wolfcharaa\MessageBus\Delivery\ControlActor;
use Wolfcharaa\MessageBus\Delivery\DeliveryAcceptance;
use Wolfcharaa\MessageBus\Delivery\DeliveryAttempt;
use Wolfcharaa\MessageBus\Delivery\DeliveryDraft;
use Wolfcharaa\MessageBus\Delivery\DeliveryPolicy;
use Wolfcharaa\MessageBus\Delivery\DeliveryState;
use Wolfcharaa\MessageBus\Delivery\DeliveryStatus;
use Wolfcharaa\MessageBus\Delivery\DurableDeliveryBackend;
use Wolfcharaa\MessageBus\Delivery\FailureCategory;
use Wolfcharaa\MessageBus\Delivery\MaintenanceReport;
use Wolfcharaa\MessageBus\Delivery\ReplayReceipt;
use Wolfcharaa\MessageBus\Identity\DeliveryId;
use Wolfcharaa\MessageBus\Identity\IdentityGenerator;
use Wolfcharaa\MessageBus\Identity\RandomIdentityGenerator;
use Wolfcharaa\MessageBus\SystemClock;

/**
 * Deterministic process-local conformance/test backend.
 *
 * It is intentionally not a production queue: process exit loses all state.
 *
 * @api adapter
 */
final class InMemoryDeliveryBackend implements DurableDeliveryBackend
{
    /** @var array<string, DeliveryDraft> */
    private array $deliveries = [];

    /** @var array<string, DeliveryStatus> */
    private array $statuses = [];

    /** @var array<string, ClaimedDelivery> */
    private array $claims = [];

    /** @var array<string, true> */
    private array $cancellations = [];

    /** @var array<string, true> */
    private array $handlerStarted = [];

    /** @var array<string, \DateTimeImmutable> */
    private array $acceptedAt = [];

    public function __construct(
        private readonly ClockInterface $clock = new SystemClock(),
        private readonly IdentityGenerator $identities = new RandomIdentityGenerator(),
    ) {}

    public function accept(DeliveryDraft $delivery): DeliveryAcceptance
    {
        $key = $delivery->deliveryId->value;
        if (isset($this->deliveries[$key])) {
            throw new \LogicException('Delivery identity already exists.');
        }
        $this->deliveries[$key] = $delivery;
        $this->acceptedAt[$key] = $this->clock->now();
        $this->statuses[$key] = new DeliveryStatus(
            $delivery->deliveryId,
            $delivery->metadata->messageId,
            $delivery->targetId,
            DeliveryState::Accepted,
            0,
            $this->clock->now(),
        );

        return new DeliveryAcceptance($delivery->deliveryId, DeliveryState::Accepted);
    }

    public function claim(string $lane, string $workerId): ?ClaimedDelivery
    {
        $eligible = array_filter(
            $this->deliveries,
            fn(DeliveryDraft $draft, string $key): bool => $draft->lane === $lane
                && \in_array($this->statuses[$key]->state, [DeliveryState::Accepted, DeliveryState::RetryWait], true),
            ARRAY_FILTER_USE_BOTH,
        );
        uasort($eligible, static function (DeliveryDraft $a, DeliveryDraft $b): int {
            $priority = $b->policy->priority <=> $a->policy->priority;

            return $priority !== 0
                ? $priority
                : $a->deliveryId->value <=> $b->deliveryId->value;
        });
        $delivery = reset($eligible);
        if (!$delivery instanceof DeliveryDraft) {
            return null;
        }

        $key = $delivery->deliveryId->value;
        $status = $this->statuses[$key];
        $now = $this->clock->now();
        $claim = new ClaimedDelivery(
            $delivery,
            new DeliveryAttempt(
                $this->identities->attemptId(),
                $delivery->deliveryId,
                $status->attempts + 1,
                $now,
            ),
            $this->identities->leaseToken(),
            $now->modify('+' . $delivery->policy->leaseSeconds . ' seconds'),
        );
        $this->claims[$key] = $claim;
        $this->statuses[$key] = new DeliveryStatus(
            $delivery->deliveryId,
            $delivery->metadata->messageId,
            $delivery->targetId,
            DeliveryState::Running,
            $status->attempts + 1,
            $now,
        );

        return $claim;
    }

    public function heartbeat(ClaimedDelivery $claim): bool
    {
        return $this->owns($claim);
    }

    public function cancellationRequested(ClaimedDelivery $claim): bool
    {
        return $this->owns($claim) && isset($this->cancellations[$claim->delivery->deliveryId->value]);
    }

    public function markHandlerStarted(ClaimedDelivery $claim, ?int $childPid = null): bool
    {
        if (!$this->owns($claim)) {
            return false;
        }
        $this->handlerStarted[$claim->delivery->deliveryId->value] = true;

        return true;
    }

    public function succeed(ClaimedDelivery $claim): bool
    {
        if (!$this->owns($claim)) {
            return false;
        }
        $this->settle($claim, DeliveryState::Succeeded);

        return true;
    }

    public function fail(ClaimedDelivery $claim, FailureCategory $category, string $summary): bool
    {
        if (!$this->owns($claim)) {
            return false;
        }
        $attempts = $this->statuses[$claim->delivery->deliveryId->value]->attempts;
        $state = match (true) {
            $category === FailureCategory::Cancelled => DeliveryState::Cancelled,
            $category === FailureCategory::OutcomeUnknown => DeliveryState::OutcomeUnknown,
            $category === FailureCategory::Retryable && $attempts < $claim->delivery->policy->maxAttempts => DeliveryState::RetryWait,
            default => DeliveryState::Failed,
        };
        $this->settle($claim, $state, $category->value);

        return true;
    }

    public function status(DeliveryId $deliveryId): ?DeliveryStatus
    {
        return $this->statuses[$deliveryId->value] ?? null;
    }

    public function cancel(DeliveryId $deliveryId, ControlActor $actor): CancellationReceipt
    {
        $status = $this->status($deliveryId);
        $now = $this->clock->now();
        if (!$status instanceof DeliveryStatus) {
            return new CancellationReceipt($deliveryId, CancellationOutcome::NotFound, $now);
        }
        if ($status->state->isTerminal()) {
            return new CancellationReceipt($deliveryId, CancellationOutcome::AlreadyTerminal, $now);
        }
        if (isset($this->cancellations[$deliveryId->value])) {
            return new CancellationReceipt($deliveryId, CancellationOutcome::AlreadyRequested, $now);
        }

        $this->cancellations[$deliveryId->value] = true;
        if ($status->state !== DeliveryState::Running) {
            $this->statuses[$deliveryId->value] = new DeliveryStatus(
                $status->deliveryId,
                $status->messageId,
                $status->targetId,
                DeliveryState::Cancelled,
                $status->attempts,
                $now,
            );

            return new CancellationReceipt($deliveryId, CancellationOutcome::CancelledBeforeStart, $now);
        }

        return new CancellationReceipt($deliveryId, CancellationOutcome::RequestedRunning, $now);
    }

    public function recoverExpired(int $limit): MaintenanceReport
    {
        $examined = 0;
        $changed = 0;
        $now = $this->clock->now();
        foreach ($this->claims as $key => $claim) {
            if ($examined >= $limit) {
                break;
            }
            ++$examined;
            if ($claim->leaseExpiresAt > $now) {
                continue;
            }
            $state = $claim->delivery->policy->retryLostAttempts
                && $this->statuses[$key]->attempts < $claim->delivery->policy->maxAttempts
                ? DeliveryState::RetryWait
                : DeliveryState::OutcomeUnknown;
            $this->settle($claim, $state, 'lost');
            ++$changed;
        }

        return new MaintenanceReport($examined, $changed, \count($this->claims) > $examined);
    }

    public function replay(DeliveryId $deliveryId, DeliveryPolicy $policy, ControlActor $actor): ReplayReceipt
    {
        $key = $deliveryId->value;
        $original = $this->deliveries[$key] ?? null;
        $status = $this->statuses[$key] ?? null;
        if (!$original instanceof DeliveryDraft || !$status instanceof DeliveryStatus || !$status->state->isTerminal()) {
            throw new \LogicException('Only a retained terminal delivery can be replayed.');
        }
        if ($status->state === DeliveryState::Succeeded) {
            throw new \LogicException('A successful delivery cannot be operationally replayed.');
        }
        if ($status->state === DeliveryState::OutcomeUnknown && !$policy->idempotent) {
            throw new \LogicException('Outcome-unknown delivery requires an idempotent replay policy.');
        }
        if ($status->state === DeliveryState::OutcomeUnknown) {
            $acceptedAt = $this->acceptedAt[$key]
                ?? throw new \LogicException('Retained delivery has no acceptance timestamp.');
            if ($original->policy->replayWindowSeconds < 1
                || $acceptedAt->modify('+' . $original->policy->replayWindowSeconds . ' seconds') < $this->clock->now()) {
                throw new \LogicException('Outcome-unknown delivery is outside its persisted replay safety window.');
            }
        }

        $newId = $this->identities->deliveryId();
        $this->accept(new DeliveryDraft(
            $newId,
            $original->metadata,
            $original->payload,
            $original->targetId,
            $original->handlerClass,
            $original->handlerMethod,
            $original->lane,
            $policy,
            $deliveryId,
            $original->role,
        ));

        return new ReplayReceipt($deliveryId, $newId, $policy);
    }

    public function prune(\DateTimeImmutable $terminalBefore, int $limit, bool $execute): MaintenanceReport
    {
        $matches = [];
        foreach ($this->statuses as $key => $status) {
            if (\count($matches) >= $limit) {
                break;
            }
            if ($status->state->isTerminal() && $status->updatedAt < $terminalBefore) {
                $matches[] = $key;
            }
        }
        if ($execute) {
            foreach ($matches as $key) {
                unset($this->deliveries[$key], $this->statuses[$key], $this->claims[$key], $this->cancellations[$key], $this->handlerStarted[$key], $this->acceptedAt[$key]);

            }
        }

        return new MaintenanceReport(\count($matches), $execute ? \count($matches) : 0, false);
    }

    private function owns(ClaimedDelivery $claim): bool
    {
        $current = $this->claims[$claim->delivery->deliveryId->value] ?? null;

        return $current instanceof ClaimedDelivery
            && $current->attempt->attemptId->value === $claim->attempt->attemptId->value
            && $current->leaseToken->value === $claim->leaseToken->value;
    }

    private function settle(ClaimedDelivery $claim, DeliveryState $state, ?string $failure = null): void
    {
        $key = $claim->delivery->deliveryId->value;
        $status = $this->statuses[$key];
        $this->statuses[$key] = new DeliveryStatus(
            $status->deliveryId,
            $status->messageId,
            $status->targetId,
            $state,
            $status->attempts,
            $this->clock->now(),
            $failure,
        );
        unset($this->claims[$key]);
    }
}
