# Cake Queue worker adapter

Cake Queue adapter не является частью core, но это эталонный пример framework integration.

## Producer

Cake provider должен сохранить не только payload, но и runtime metadata:

- `transport`;
- `queue`;
- `message_id`;
- `correlation_id`;
- `flow`;
- `binding_id`;
- `available_at`;
- `priority`;
- `serialized_envelope`.

```php
final class CakeJobQueueProvider implements QueueProviderInterface
{
    public function enqueue(QueueMessage $message): QueueEnqueueResult
    {
        $jobId = $this->jobs->create([
            'transport' => $message->transport,
            'queue' => $message->queue,
            'message_id' => $message->messageId,
            'correlation_id' => $message->correlationId,
            'flow' => $message->flow,
            'binding_id' => $message->bindingId,
            'payload' => $message->envelope,
        ]);

        return new QueueEnqueueResult((string) $jobId);
    }
}
```

## Worker entrypoint

```php
final class QueueMessageBusTask
{
    public function __construct(private QueueWorkerInterface $worker) {}

    public function run(array $payload): void
    {
        $envelope = $this->restoreSerializedEnvelope($payload);

        $this->worker->handle($envelope);
    }
}
```

Если Cake Queue уже управляет lifecycle, adapter может сам делать retry/reject, а MessageBus worker только исполняет конкретный envelope.

## Docker/runtime lessons

Built-in `worker:run --mode=auto --workers=N` закрывает часть задач, которые раньше решал shell supervisor:

- master process;
- child workers через `pcntl`;
- fresh bootstrap в child process;
- graceful shutdown;
- memory/runtime/message limits;
- filters by queue/flow/binding pattern;
- stale job recovery через PostgreSQL storage.

## Envelope normalizer for Cake adapters

Cake integrations should reuse `Wolfcharaa\MessageBus\Envelope\SerializedEnvelopeNormalizer` instead of rebuilding the envelope array manually. This keeps the PostgreSQL `serialized_envelope` format identical to the core adapter.

New records are written with camelCase keys and include payload encoding:

```json
{
  "schemaVersion": 1,
  "message": {
    "name": "app.message",
    "contentType": "application/json",
    "payload": "{}",
    "payloadEncoding": "plain",
    "headers": {}
  },
  "headers": {},
  "messageId": "1",
  "causationId": null,
  "correlationId": "1",
  "flow": "default",
  "bindingId": null,
  "createdAt": "2026-08-20T07:00:00+00:00"
}
```

The default normalizer also reads legacy snake_case keys, so existing rows can be consumed while new rows are stored in the normalized camelCase format.
