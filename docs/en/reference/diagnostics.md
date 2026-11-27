---
doc_id: reference.diagnostics
contract_revision: 3
---

# Diagnostics reference

<a href="../../ru/reference/diagnostics.md">Русская версия</a>

This is the complete reference for the library-owned `MBR`, `MBC`, `MBD`, and `MBCLI` codes. A code identifies a stable rule. The English `message` is self-contained: it repeats the code, exact class/method/target, expected state, and observed state. Automation should still compare structured fields rather than parse prose.

## Reading a diagnostic

A registry diagnostic contains:

- `code` and `rule`: stable rule identifier;
- `severity`: `error` or `warning`;
- `message`: complete human-readable explanation;
- `symbol`: class, `Class::method`, message name, context class, or target that failed;
- `subject_kind`: `symbol` for compiler rules or `target` for runtime configuration;
- `expected` and `actual`: violated contract and observed declaration/configuration;
- `cause_class`: exception class when an attribute constructor failed.

A private static handler, for example, produces:

```text
[MBR002] A method marked as a handler has an invalid attribute count, visibility, or static modifier. Subject: App\QueryHandler::handle. Expected: exactly 1 #[Handler] attribute on a public non-static method. Actual: 1 #[Handler] attribute(s), private, static.
```

`RegistryCompilationResult::requireRegistry()` includes every error message in `InvalidMessageBusConfiguration::getMessage()` and exposes the typed list through `InvalidMessageBusConfiguration::$diagnostics`.

## Registry compiler (`MBR`)

| Code | Exact condition and location | Correction |
| --- | --- | --- |
| `MBR001` | A class returned by `RegistryClassSource` is not loadable; `symbol` is the exact class and `actual` reports the failed `class_exists()` check. | Fix Composer autoload or remove the stale explicit entry, then rebuild the autoloader. |
| `MBR002` | A method has `#[Handler]`, but the attribute count is not one, the method is not public, or it is static; `symbol` is `Class::method` and `actual` lists count, visibility, and modifier. | Leave exactly one `#[Handler]` on one public non-static method. |
| `MBR003` | A handler has zero or more than two parameters; `actual` is the exact count. | Declare one message parameter and, only when required, one context parameter. |
| `MBR004` | The first parameter type is missing, built-in, union/intersection, not loadable, or does not implement `Message`; `actual` is the declared reflection type. | Use one named, Composer-loadable class implementing exactly one message role. |
| `MBR005` | The second parameter is not one named, loadable subclass of `MessageContext`; `actual` is its declared type. | Type it with the intended `MessageContext` subclass. |
| `MBR006` | A valid context class has no exact factory in `MessageContextFactoryResolver`; `symbol` is the context class. | Register a factory for that exact class; a parent or sibling does not match. |
| `MBR007` | A Command or Event handler does not explicitly return `void`; `actual` is its type or `not declared`. | Add an explicit `: void`. |
| `MBR008` | A Query or Request handler has no return type. | Declare the result type promised by the message contract. |
| `MBR009` | A Query handler explicitly returns `void`. | Declare a non-void type matching `Query<TResult>`. |
| `MBR010` | An Event handler has a null or empty `subscriptionId`; `symbol` is the handler method. | Set a stable `#[Handler('subscription.id')]` identity. |
| `MBR011` | A non-Event handler declares an Event-only `subscriptionId`; `actual` includes the ID. | Remove the argument; only Event subscriptions have independent IDs. |
| `MBR012` | A durable Command or Event has other than exactly one `#[MessageName]`; `actual` is the count. | Put exactly one valid `#[MessageName('wire.name')]` on the message class. |
| `MBR013` | A message implements none or multiple roles from `Query`, `Request`, `Command`, `Event`; `actual` lists them. | Implement exactly one role interface. |
| `MBR014` | A Query, Request, or Command has zero or multiple compiled handlers; `symbol` is the message class and `actual` is the count. | Make exactly one valid handler discoverable. Fix earlier handler diagnostics before adding another handler. |
| `MBR015` | Two bindings for one message have the same ID; `actual` lists duplicates. | Give Event subscriptions unique IDs; non-Event messages must retain one handler. |
| `MBR016` | Two durable message classes use the same `MessageName`; `symbol` is the wire name and `actual` names both classes. | Assign a unique stable wire name to each durable message. |
| `MBR017` | Constructing `#[Handler]` threw, normally because `subscriptionId` violates its grammar; `cause_class` preserves the exception type. | Match `[a-z0-9][a-z0-9._:-]{2,127}`. |
| `MBR018` | A handler parameter is optional or variadic; `actual` identifies the parameter and violation. | Make every parameter required and non-variadic. |
| `MBR019` | The first parameter implements `Message` but is not instantiable, such as an interface or abstract class; `actual` is that type. | Handle a concrete message class. |
| `MBR020` | Constructing `#[MessageName]` threw; `symbol` is the durable class and `cause_class` preserves the exception type. | Match `[a-z][a-z0-9._:-]{2,127}`. |

## Runtime configuration (`MBC`)

`RuntimeConfigurationRule` emits these codes. `symbol` and `message` identify the exact compiled target.

| Code | Exact condition | Correction |
| --- | --- | --- |
| `MBC001` | A Query or Request target has a route although it executes inline; `actual` includes mode, policy, and lane. | Remove the unused `DeliveryRoute`. |
| `MBC002` | A Command or Event target has no route. | Configure one route for the target ID in `symbol`. |
| `MBC003` | A Command route uses `inline` or `test_inline`; `actual` shows the mode. | Set mode to `durable`. |
| `MBC004` | A durable route names a policy absent from `DeliveryPolicies`; `actual` includes the name. | Register that exact policy or correct the route. |
| `MBC005` | An idempotent policy is used without an `IdempotencyRuntime` atomic boundary. | Compose `IdempotencyRuntime` with the durable runtime. |
| `MBC006` | `retryLostAttempts=true` while `idempotent=false`. | Enable idempotency with a correct atomic boundary or disable automatic retry. |
| `MBC007` | An identity resolver references a target absent from the compiled registry. | Remove/correct the stale ID or make the intended target discoverable. |
| `MBC008` | An idempotent target has no resolver for its exact target ID. | Register a target-scoped resolver for `symbol`. |

## Runtime failure descriptors (`MBD`)

`FailureDiagnostic` is a safe descriptor retained in status and emitted with runtime events. `summary` starts with the code; `stage`, `failure_class`, optional `related_failure_class`, and `fingerprint` remain separate. Arbitrary exception messages, traces, payloads, and secrets are excluded. The original throwable remains in the synchronous exception chain and is passed to `RuntimeFailureSink` where available.

| Code / stage | What failed | What to inspect |
| --- | --- | --- |
| `MBD001` / `dispatch` | Dispatch failed before returning a result. | `failure_class`, message class/ID, correlation ID, target ID, and thrown exception. |
| `MBD101` / `payload_decode` | A persisted payload could not be decoded into its message class. | Codec/name compatibility, payload version, delivery/message IDs, `failure_class`. |
| `MBD102` / `target_resolution` | A persisted target is absent or incompatible with the compiled registry. | Target ID, registry/build fingerprint, deployment compatibility, `failure_class`. |
| `MBD103` / `context_creation` | The handler context factory failed. | Handler/context registration, delivery/attempt IDs, `failure_class`. |
| `MBD104` / `handler_resolution` | The DI container could not resolve the compiled handler service. | Target/handler class and the original container exception; the library does not replace it with a generic handler error. |
| `MBD105` / `handler_execution` | The resolved handler threw while processing the message. | Target, message/delivery/attempt IDs, `failure_class`, original handler exception. |
| `MBD106` / `idempotency` | Identity resolution or the atomic idempotency boundary failed. | Target/attempt IDs, resolver/boundary configuration, `failure_class`. |
| `MBD107` / `failure_classification` | The application classifier threw while handling another failure. | Classifier `failure_class` and primary `related_failure_class`. |
| `MBD108` / `delivery_settlement` | Persisting the failed-delivery outcome also failed. | Settlement `failure_class`, primary `related_failure_class`, delivery/attempt IDs. |
| `MBD109` / `worker_fork` | The supervisor could not fork a worker child. | Worker/profile metadata and `failure_class`. |
| `MBD110` / `lease_recovery` | Recovery found that the worker no longer owns the attempt. | Delivery/attempt IDs, lease timing, worker identity, backend state. |
| `MBD111` / `delivery_acceptance` | The durable backend did not accept a Command or Event delivery. | Message/target IDs, backend `failure_class`, `CommandNotAccepted` fields. |

Schema-1 descriptors written before the expanded summaries remain readable: decoding verifies the stable code and fingerprint and replaces the old display summary with the current canonical one.

## CLI failures (`MBCLI`)

Every error object contains `code`, `type`, `stage`, `message`, `exception_type`, and optional `subject`. The message repeats code, stage, subject type/ID when known, and exception class. It never copies the arbitrary exception message.

| Code / type | Meaning | Next check |
| --- | --- | --- |
| `MBCLI001` / `invalid_input` | A command option or constructed value failed validation. | Use `stage`, `subject`, and command help to find the accepted grammar. |
| `MBCLI002` / `state_conflict` | The mutation conflicts with retained state or revision. | Re-read status/revision and retry only if still valid. |
| `MBCLI003` / `backend_failure` | A PDO-backed command operation failed. | Locate the record by `stage`/`subject`, then inspect protected backend logs. |
| `MBCLI004` / `internal_failure` | An unexpected non-input, non-state, non-PDO failure escaped. | Use `stage`, `subject`, `exception_type`, and protected logs. |

## Composer discovery and misplaced classes

The registry can diagnose only classes returned by `RegistryClassSource`:

1. An explicit entry that is not autoloadable produces `MBR001` with the exact class.
2. A discovered handler whose first parameter is not a loadable message class produces `MBR004` with `Class::method` and the declared type.
3. A discovered Query, Request, or Command whose handler is missing from the classmap produces `MBR014` with `actual: 0 compiled handler(s)`.
4. If both message and handler are outside every Composer mapping, neither is visible, so the registry cannot report them.

Catch the fourth case at the autoload boundary:

```bash
composer dump-autoload --optimize --strict-psr
```

Also verify that `ComposerClassMapSource` receives the generated classmap and intended namespace prefix. Rebuild the registry artifact after correcting autoload metadata.

Diagnostics use deterministic class/method order. Fix an earlier signature or attribute error before a later cardinality error such as `MBR014`: the missing binding can be a consequence of the first rejection.

Exact signatures are in the [public API](public-api.md); source and compatibility catalogs are normative if prose conflicts with executable behavior.
