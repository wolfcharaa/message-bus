---
doc_id: guides.troubleshooting-diagnostics
contract_revision: 2
---

# Diagnose and resolve failures

<a href="../../ru/guides/troubleshooting-diagnostics.md">Русская версия</a>

This guide owns the response sequence for a failure. The exact condition and
correction for every code live in the [diagnostics reference](../reference/diagnostics.md).

## Safe response sequence

1. Preserve the original exception and its complete `previous` chain. Do not
   replace a DI container, handler, codec, or backend failure with a generic
   application error.
2. Record structured context: code, stage, symbol/target, expected, actual,
   message/delivery/attempt ID, `failure_class`, and `related_failure_class`
   where available.
3. Select the correction owner by code prefix.
4. Look up the exact code and correct the cause rather than the message text.
5. Repeat the smallest check, then rebuild the registry artifact or retry only
   the affected delivery according to its policy.

| Prefix | Boundary | Source of facts | Action |
| --- | --- | --- | --- |
| `MBR` | Registry discovery/compilation | `RegistryDiagnostic`: `symbol`, `source_path`, `expected`, `actual`, `cause_class`; original cause in `previous` | Correct mapping/declaration/autoload and compile again. Do not start the runtime. |
| `MBC` | Runtime configuration validation | Target, route, policy, idempotency/resolver fields | Correct the composition root and repeat preflight. Do not retry a delivery. |
| `MBD` | Dispatch or worker attempt | Runtime event/status, IDs, failure classes, and original throwable | Locate the failed boundary, inspect protected logs, and retry only through `FailureCategory`/policy. |
| `MBCLI` | Operator CLI | `stage`, `subject`, `exception_type`, exit code | Correct input/state or continue in protected backend/application logs. |

## Use the right channel first

- A synchronous dispatch failure returns to the caller unchanged. The full
  throwable is the primary cause; `dispatch_failed` is for correlation and
  automation.
- A worker passes the original failure to `RuntimeFailureSink`, while the safe
  `FailureDiagnostic` is retained in the runtime event and delivery status.
- The CLI deliberately excludes arbitrary exception messages, traces, payloads,
  and credentials. `MBCLI` locates the operation; detail remains in protected
  application/backend logs.

## Common cases

### Handler missing or declared incorrectly

Start with the earliest `MBR` for the class. `MBR002`–`MBR011`, `MBR018`, and
`MBR019` identify the exact method or signature violation. A later `MBR014` is
often a consequence of the invalid handler being excluded from compiled
bindings. Correct the earlier diagnostic before investigating cardinality.

If the DI container fails during dispatch, `MBD104` preserves the
`handler_resolution` stage and the library rethrows the original container
exception. Correct the registration/factory named by that exception; do not
catch and replace it with a generic `Handler failed` error.

### Invalid `MessageName`

- `MBR012`: the attribute is absent or declared more than once;
- `MBR016`: another durable message already owns the wire name;
- `MBR020`: the value violates `[a-z][a-z0-9._:-]{2,127}`.

After correcting durable identity, rebuild the registry artifact and verify
compatibility with retained payloads before removing an old name or codec.

### Class outside Composer mappings

Check the autoload boundary first:

```bash
composer dump-autoload --optimize --strict-psr
```

`ComposerDirectoryClassSource` checks the complete selected production root: a
wrong namespace/path produces `MBR026` with the actual `source_path` and expected
file; a duplicate FQCN produces `MBR025`; a case collision produces `MBR027`.
An explicit invisible class produces `MBR001`; a handler with an unusable
message type produces `MBR004`; a visible Query/Request/Command without a
handler produces `MBR014`.

A class outside explicitly selected mappings/directories remains outside the
bounded scan. For a new integration, use [Composer directory discovery](../getting-started/composer-directory-discovery.md),
correct the mapping/location, and rebuild the artifact.

## Verify the correction

The application-owned bootstrap below is a placeholder and must be replaced by
the consumer's real file:

```bash
MESSAGE_BUS_BOOTSTRAP=/path/to/message-bus-bootstrap.php \
  vendor/bin/message-bus registry:check --format=json

MESSAGE_BUS_BOOTSTRAP=/path/to/message-bus-bootstrap.php \
  vendor/bin/message-bus delivery:status \
  --delivery-id='delivery:example' --format=json
```

For `MBR`/`MBC`, require a valid result before starting the runtime. For `MBD`,
verify that a new attempt uses the corrected build/artifact and that its policy
actually permits retry. Do not add secret metadata or raw payloads to a
portable diagnostic for convenience.
