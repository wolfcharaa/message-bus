# Container contract

`MessageBus` использует PSR-11 container для:

- handlers/actions;
- middleware;
- context factories;
- execution strategies;
- optional infrastructure fallback.

Явные constructor arguments имеют приоритет над container services.

Fallback lookup order:

| Role | FQCN/interface id | Alias |
| --- | --- | --- |
| Queue provider | `QueueProviderInterface::class` | `message_bus.queue_provider` |
| Envelope serializer | `EnvelopeSerializerInterface::class` | `message_bus.envelope_serializer` |
| Invoker | `CallableInvokerInterface::class` | `message_bus.invoker` |
| Message id generator | `MessageIdGenerator::class` | `message_bus.message_id_generator` |
| Clock | `ClockInterface::class` | `message_bus.clock` |
| Retry policy registry | `RetryPolicyRegistryInterface::class` | `message_bus.retry_policy_registry` |

Container lookup errors:

- `ContainerServiceNotFound`
- `ContainerServiceInvalid`

Сообщения содержат service ids, role, bindingId, flow и expected type, если они применимы.
