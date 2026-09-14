# Idempotency

`Wolfcharaa\MessageBus\Idempotency` - optional extension для state-changing MessageBus execution.

Idempotency применяется к `CommandHandler` и `EventSubscriber`. `QueryHandler` не поддерживается: query должна возвращать business result сразу, а idempotency replay для state-changing сценария является `void` no-op.

## Модель

Execution identity строится по stable `bindingId`.

1. Middleware получает текущий `bindingId` из `context->envelope()`.
2. По `bindingId` берется `ResolvedIdempotencyPolicy`.
3. Scenario-local `IdempotencyKeyExtractorInterface` извлекает ключ из message.
4. Scenario-local `IntentFingerprintFactoryInterface` строит fingerprint из business fields message.
5. `IdempotencyStoreInterface::claim(...)` возвращает decision.
6. `Claimed` выполняет handler и после успеха вызывает `complete(...)`.
7. `CompletedSame` возвращает `void` no-op.
8. `InProgress` становится retryable failure.
9. `Conflict` становится non-retryable failure.

Middleware не открывает transaction. Он должен выполняться внутри application transaction/unit-of-work middleware, если claim, business change и complete должны быть атомарны.

## Flow contract

Для idempotent flow используйте готовый contract:

```php
use Wolfcharaa\MessageBus\Flow\Contract\FlowContractRegistry;
use Wolfcharaa\MessageBus\Flow\Contract\FlowContractValidationRule;
use Wolfcharaa\MessageBus\Flow\Contract\MiddlewareRoleRegistry;
use Wolfcharaa\MessageBus\Idempotency\IdempotencyFlowContract;
use Wolfcharaa\MessageBus\Idempotency\IdempotencyMiddlewareRole;
use Wolfcharaa\MessageBus\Idempotency\RequiresIdempotencyKey;

$roles = (new MiddlewareRoleRegistry())
    ->register(TransactionMiddleware::class, 'transaction_boundary')
    ->register(RequiresIdempotencyKey::class, IdempotencyMiddlewareRole::Guard);

$contracts = new FlowContractRegistry(
    IdempotencyFlowContract::requiresIdempotencyKey('business_command')
        ->requiresOrder('transaction_boundary', IdempotencyMiddlewareRole::Guard),
);

$compiler = new MessageRegistryCompiler(validationRules: [
    new FlowContractValidationRule($contracts, $roles, [$resolvedIdempotencyPolicies]),
]);
```

Контракт проверяет:

- idempotency middleware role присутствует;
- `transaction_boundary` идет перед `idempotency_guard`, если такой order объявлен;
- binding имеет explicit stable `bindingId`;
- binding имеет owner metadata;
- для binding зарегистрирована idempotency policy;
- owner policy совпадает с owner binding;
- flow содержит только command/event handlers.

## Handler

```php
#[CommandHandler(
    message: CreateOrder::class,
    flow: 'business_command',
    bindingId: 'orders.create',
    ownerKind: 'core',
    ownerId: 'orders',
)]
final class CreateOrderHandler
{
    public function __invoke(CreateOrder $message, MessageContextInterface $context): void
    {
        // business mutation
    }
}
```

## Policy

Policy descriptor содержит service references, а не live services:

```php
use Wolfcharaa\MessageBus\Bootstrap\ServiceReference\ServiceReference;
use Wolfcharaa\MessageBus\Idempotency\IdempotencyPolicyDescriptor;

IdempotencyPolicyDescriptor::forBinding(
    'orders.create',
    ServiceReference::class(CreateOrderIdempotencyKey::class),
    ServiceReference::class(CreateOrderFingerprint::class),
    ServiceReference::class(PostgresIdempotencyStore::class),
    retentionSeconds: 86400,
);
```

Provider задает owner/source один раз:

```php
final class OrdersIdempotencyPolicies implements IdempotencyPolicyProviderInterface
{
    public function owner(): RegistryOwner
    {
        return RegistryOwner::core('orders');
    }

    public function source(): RegistrySource
    {
        return RegistrySource::provider(self::class, package: 'orders');
    }

    public function policies(): iterable
    {
        yield IdempotencyPolicyDescriptor::forBinding(...);
    }
}
```

`IdempotencyPolicyResolver` превращает descriptors в frozen `ResolvedIdempotencyPolicyRegistry` через `ServiceReferenceResolverInterface`.

## Store contract

`IdempotencyStoreInterface` имеет semantic API:

```php
public function claim(
    IdempotencyExecution $execution,
    IdempotencyKey $key,
    IntentFingerprint $fingerprint,
): IdempotencyDecisionInterface;

public function complete(
    IdempotencyClaim $claim,
    IdempotencyCompletion $completion,
): void;
```

Store сам скрывает locks, upsert, unique constraints и `SELECT FOR UPDATE`. Если store требует active transaction, отсутствие transaction должно быть non-retryable configuration error, например `IdempotencyTransactionRequired`.

## Fingerprint

`IntentFingerprint` сравнивается по `algorithm + version + digest`. Он строится из canonical business fields message и не должен включать delivery metadata, retry, queue id, headers или текущий time.

`IdempotencyKey` хранит raw normalized value и SHA-256 digest. Storage adapter может решать, сохранять ли raw key для diagnostics, но portable uniqueness обычно строится на `bindingId + key digest`.
