<?php

declare(strict_types=1);

use Wolfcharaa\MessageBus\Delivery\CancellationOutcome;
use Wolfcharaa\MessageBus\Delivery\ClaimedDelivery;
use Wolfcharaa\MessageBus\Delivery\ControlActor;
use Wolfcharaa\MessageBus\Delivery\DeliveryDraft;
use Wolfcharaa\MessageBus\Delivery\DeliveryPolicy;
use Wolfcharaa\MessageBus\Delivery\DeliveryState;
use Wolfcharaa\MessageBus\Delivery\DurableDeliveryBackend;
use Wolfcharaa\MessageBus\Delivery\FailureCategory;

require __DIR__ . '/autoload.php';

$bootstrap = getenv('MESSAGE_BUS_CONFORMANCE_BOOTSTRAP');
if (!is_string($bootstrap) || $bootstrap === '' || !is_file($bootstrap)) {
    fwrite(STDERR, "Set MESSAGE_BUS_CONFORMANCE_BOOTSTRAP to a disposable fixture.\n");
    exit(2);
}
$fixture = require $bootstrap;
if (!is_array($fixture)
    || !($fixture['backend'] ?? null) instanceof DurableDeliveryBackend
    || !($fixture['actor'] ?? null) instanceof ControlActor
    || !($fixture['draft'] ?? null) instanceof Closure
    || !($fixture['expire'] ?? null) instanceof Closure) {
    throw new RuntimeException('Conformance bootstrap returned an invalid fixture.');
}

/** @var DurableDeliveryBackend $backend */
$backend = $fixture['backend'];
/** @var ControlActor $actor */
$actor = $fixture['actor'];
/** @var Closure(string, DeliveryPolicy): DeliveryDraft $draft */
$draft = $fixture['draft'];
/** @var Closure(ClaimedDelivery): void $expire */
$expire = $fixture['expire'];

$successful = $draft('successful', new DeliveryPolicy('successful'));
$accepted = $backend->accept($successful);
$claim = $backend->claim($successful->lane, 'conformance:worker');
if (!$claim instanceof ClaimedDelivery || !$backend->heartbeat($claim)
    || !$backend->markHandlerStarted($claim, 1234) || !$backend->succeed($claim)
    || $backend->succeed($claim)) {
    throw new RuntimeException('Fenced claim/heartbeat/start/settlement conformance failed.');
}

$retry = $draft('retry', new DeliveryPolicy('retry', maxAttempts: 2));
$backend->accept($retry);
$firstRetry = $backend->claim($retry->lane, 'conformance:worker');
if (!$firstRetry instanceof ClaimedDelivery
    || !$backend->fail($firstRetry, FailureCategory::Retryable, 'retryable')
    || $backend->status($retry->deliveryId)?->state !== DeliveryState::RetryWait) {
    throw new RuntimeException('Retry scheduling conformance failed.');
}
$secondRetry = $backend->claim($retry->lane, 'conformance:worker');
if (!$secondRetry instanceof ClaimedDelivery
    || !$backend->fail($secondRetry, FailureCategory::Terminal, 'terminal')
    || $backend->status($retry->deliveryId)?->state !== DeliveryState::Failed) {
    throw new RuntimeException('Retry budget/terminal settlement conformance failed.');
}

$cancelled = $draft('cancel-before-start', new DeliveryPolicy('cancel-before-start'));
$backend->accept($cancelled);
if ($backend->cancel($cancelled->deliveryId, $actor)->outcome !== CancellationOutcome::CancelledBeforeStart
    || $backend->cancel($cancelled->deliveryId, $actor)->outcome !== CancellationOutcome::AlreadyTerminal) {
    throw new RuntimeException('Before-start cancellation conformance failed.');
}

$running = $draft('cancel-running', new DeliveryPolicy('cancel-running'));
$backend->accept($running);
$runningClaim = $backend->claim($running->lane, 'conformance:worker');
if (!$runningClaim instanceof ClaimedDelivery
    || $backend->cancel($running->deliveryId, $actor)->outcome !== CancellationOutcome::RequestedRunning
    || !$backend->cancellationRequested($runningClaim)
    || !$backend->fail($runningClaim, FailureCategory::Cancelled, 'cancelled')) {
    throw new RuntimeException('Running cancellation conformance failed.');
}

$lost = $draft('lost-safe', new DeliveryPolicy(
    'lost-safe',
    maxAttempts: 2,
    leaseSeconds: 1,
    idempotent: true,
    replayWindowSeconds: 60,
    retryLostAttempts: true,
));
$backend->accept($lost);
$lostClaim = $backend->claim($lost->lane, 'conformance:worker');
if (!$lostClaim instanceof ClaimedDelivery) {
    throw new RuntimeException('Lost-attempt claim conformance failed.');
}
$expire($lostClaim);
if ($backend->recoverExpired(100)->changed < 1
    || $backend->status($lost->deliveryId)?->state !== DeliveryState::RetryWait) {
    throw new RuntimeException('Safe lost-attempt recovery conformance failed.');
}

$dryRun = $backend->prune(new DateTimeImmutable('+1 day'), 100, false);
if ($dryRun->examined < 3 || $dryRun->changed !== 0) {
    throw new RuntimeException('Prune dry-run conformance failed.');
}
$pruned = $backend->prune(new DateTimeImmutable('+1 day'), 100, true);
if ($pruned->changed < 3) {
    throw new RuntimeException('Prune execution conformance failed.');
}

echo json_encode([
    'schema' => 2,
    'accepted' => $accepted->state->value,
    'fenced_settlement' => 'passed',
    'retry_budget' => 'passed',
    'cancellation' => 'passed',
    'lost_recovery' => 'passed',
    'prune' => 'passed',
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
