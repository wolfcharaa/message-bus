<?php

declare(strict_types=1);

use Wolfcharaa\MessageBus\Idempotency\AtomicIdempotencyBoundary;
use Wolfcharaa\MessageBus\Idempotency\IdempotencyAttempt;
use Wolfcharaa\MessageBus\Idempotency\IdempotencyMaintenance;
use Wolfcharaa\MessageBus\Idempotency\IdempotencyOutcome;

require __DIR__ . '/autoload.php';

$bootstrap = getenv('MESSAGE_BUS_IDEMPOTENCY_CONFORMANCE_BOOTSTRAP');
if (!is_string($bootstrap) || $bootstrap === '' || !is_file($bootstrap)) {
    fwrite(STDERR, "Set MESSAGE_BUS_IDEMPOTENCY_CONFORMANCE_BOOTSTRAP to a disposable fixture.\n");
    exit(2);
}
$fixture = require $bootstrap;
if (!is_array($fixture)
    || !($fixture['boundary'] ?? null) instanceof AtomicIdempotencyBoundary
    || !($fixture['maintenance'] ?? null) instanceof IdempotencyMaintenance
    || !($fixture['attempt'] ?? null) instanceof Closure
    || !($fixture['effect'] ?? null) instanceof Closure
    || !($fixture['effect_count'] ?? null) instanceof Closure
    || !($fixture['prune_at'] ?? null) instanceof DateTimeImmutable) {
    throw new RuntimeException('Idempotency conformance bootstrap returned an invalid fixture.');
}

/** @var AtomicIdempotencyBoundary $boundary */
$boundary = $fixture['boundary'];
/** @var IdempotencyMaintenance $maintenance */
$maintenance = $fixture['maintenance'];
/** @var Closure(string, string): IdempotencyAttempt $attempt */
$attempt = $fixture['attempt'];
/** @var Closure(string, bool): void $effect */
$effect = $fixture['effect'];
/** @var Closure(string): int $effectCount */
$effectCount = $fixture['effect_count'];
/** @var DateTimeImmutable $pruneAt */
$pruneAt = $fixture['prune_at'];

$same = $attempt('same', 'intent-a');
if ($boundary->execute($same, static fn() => $effect('same', false))->outcome !== IdempotencyOutcome::Executed
    || $boundary->execute($same, static fn() => $effect('same', false))->outcome !== IdempotencyOutcome::Replayed
    || $effectCount('same') !== 1) {
    throw new RuntimeException('Same-intent replay conformance failed.');
}

try {
    $boundary->execute($attempt('same', 'intent-b'), static fn() => $effect('same', false));
    throw new RuntimeException('Conflicting intent was accepted.');
} catch (LogicException) {
    // Expected terminal conflict.
}

$effectFailed = false;
try {
    $boundary->execute($attempt('rollback', 'intent-a'), static fn() => $effect('rollback', true));
} catch (RuntimeException) {
    $effectFailed = true;
}
if (!$effectFailed) {
    throw new RuntimeException('Failing effect was committed.');
}
if ($boundary->execute(
    $attempt('rollback', 'intent-a'),
    static fn() => $effect('rollback', false),
)->outcome !== IdempotencyOutcome::Executed || $effectCount('rollback') !== 1) {
    throw new RuntimeException('Handler rollback/retry conformance failed.');
}

$plan = $maintenance->pruneExpired($pruneAt, 100, false);
if ($plan->examined < 2 || $plan->changed !== 0) {
    throw new RuntimeException('Idempotency retention dry-run conformance failed.');
}
$pruned = $maintenance->pruneExpired($pruneAt, 100, true);
if ($pruned->changed < 2) {
    throw new RuntimeException('Idempotency retention execution conformance failed.');
}

echo json_encode([
    'schema' => 1,
    'same_intent' => 'passed',
    'conflicting_intent' => 'passed',
    'rollback_retry' => 'passed',
    'retention' => 'passed',
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
