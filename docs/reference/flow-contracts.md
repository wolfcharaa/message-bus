# Flow Contracts

`FlowContract` описывает не бизнес-смысл сообщения, а обязательные execution rules для выбранного `Flow`.

Контракт подключается как обычное registry validation rule:

```php
use Wolfcharaa\MessageBus\Flow\Contract\FlowContract;
use Wolfcharaa\MessageBus\Flow\Contract\FlowContractRegistry;
use Wolfcharaa\MessageBus\Flow\Contract\FlowContractValidationRule;
use Wolfcharaa\MessageBus\Flow\Contract\MiddlewareRoleRegistry;
use Wolfcharaa\MessageBus\Registry\MessageRegistryCompiler;

$roles = (new MiddlewareRoleRegistry())
    ->register(DomainEventScopeMiddleware::class, 'domain_event_scope')
    ->register(TransactionMiddleware::class, 'transaction_boundary')
    ->register(RequiresIdempotencyKey::class, 'idempotency_guard');

$contracts = new FlowContractRegistry(
    FlowContract::forFlow('business_command')
        ->requiresRole('domain_event_scope')
        ->requiresRole('transaction_boundary')
        ->requiresRole('idempotency_guard')
        ->requiresOrder('domain_event_scope', 'transaction_boundary')
        ->requiresOrder('transaction_boundary', 'idempotency_guard')
        ->requiresStableBindingId()
        ->requiresBindingOwner(),
);

$compiler = new MessageRegistryCompiler(validationRules: [
    new FlowContractValidationRule($contracts, $roles),
]);
```

## Что проверяет контракт

- Наличие middleware role в flow/binding middleware chain.
- Порядок ролей, например `transaction_boundary` перед `idempotency_guard`.
- Явный stable `bindingId`, а не auto-generated id.
- Наличие `RegistryOwner` у binding, если flow требует ownership.
- Наличие policy в registry, если flow требует policy lookup по `bindingId`.
- Допустимые handler kinds, например command/event only для idempotency flow.

`FlowContract` не хранится в `FlowDefinition`, чтобы сам flow оставался portable runtime definition. Контракты являются compile/bootstrap validation layer и могут подключаться приложением только для нужных flows.

## Middleware roles

`MiddlewareRoleRegistry` отделяет смысл middleware от имени класса:

```php
$roles->register(TransactionMiddleware::class, 'transaction_boundary');
```

Это позволяет проверять flow по semantic roles, а не по конкретным package/class names. Один middleware может иметь несколько roles.

## Policy requirements

Flow contract может требовать policy registry:

```php
FlowContract::forFlow('business_command')
    ->requiresPolicy(ResolvedIdempotencyPolicyRegistryInterface::class);
```

Policy registry должен реализовать `BindingPolicyRegistryInterface`. Если он дополнительно реализует `OwnedBindingPolicyRegistryInterface`, compiler сравнит owner policy и owner binding.

## Binding ownership

`RegistryOwner` и `RegistrySource` можно передать через handler attribute:

```php
#[CommandHandler(
    message: CreateOrder::class,
    flow: 'business_command',
    bindingId: 'orders.create',
    ownerKind: 'core',
    ownerId: 'orders',
)]
final class CreateOrderHandler {}
```

Для manual bindings используйте `BindingRegistrationContext`:

```php
$context = new BindingRegistrationContext(
    RegistryOwner::core('orders'),
    RegistrySource::provider(OrderMessageBusProvider::class),
);

$binding = $context->stamp($binding);
```

Owner equality основан только на `kind + id`. `RegistrySource` нужен для diagnostics и не участвует в equality.
