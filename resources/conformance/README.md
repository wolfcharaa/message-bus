# Adapter conformance fixtures

These scripts are shipped so third-party adapters can exercise the same public
contracts from an installed package. Run them only against disposable state.

`run-durable-backend.php` expects `MESSAGE_BUS_CONFORMANCE_BOOTSTRAP` to point
to a PHP file returning a backend, actor, a
`Closure(string, DeliveryPolicy): DeliveryDraft`, and a
`Closure(ClaimedDelivery): void` that expires the exact test lease. It verifies
acceptance, fencing, retry budget, cancellation, safe lost recovery, and dry-run
pruning.

`run-idempotency-boundary.php` expects
`MESSAGE_BUS_IDEMPOTENCY_CONFORMANCE_BOOTSTRAP` to return a boundary,
maintenance adapter, attempt/effect/effect-count closures, and a prune cutoff.
The effect closure must write through the same transaction connection as the
boundary. It verifies same-intent replay, conflicting intent, rollback/retry,
and retention dry-run behavior.

Passing these portable suites is necessary, not sufficient, for production
support. Adapter CI must additionally run concurrent claims, process crash,
connection loss, commit-acknowledgement loss, and host transaction integration
tests on every supported PostgreSQL version.
