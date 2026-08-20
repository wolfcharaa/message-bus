<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Envelope;

use DateTimeImmutable;
use InvalidArgumentException;
use Wolfcharaa\MessageBus\Serialization\SerializedMessage;

final class SerializedEnvelopeNormalizer implements SerializedEnvelopeNormalizerInterface
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(SerializedEnvelope $envelope): array
    {
        return [
            'schemaVersion' => $envelope->schemaVersion,
            'message' => [
                'name' => $envelope->message->name,
                'contentType' => $envelope->message->contentType,
                'payload' => $envelope->message->payload,
                'payloadEncoding' => $envelope->message->payloadEncoding,
                'headers' => $envelope->message->headers,
            ],
            'headers' => $envelope->headers,
            'messageId' => $envelope->messageId,
            'causationId' => $envelope->causationId,
            'correlationId' => $envelope->correlationId,
            'flow' => $envelope->flow,
            'bindingId' => $envelope->bindingId,
            'createdAt' => $envelope->createdAt->format(DATE_ATOM),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public function fromArray(array $data): SerializedEnvelope
    {
        $message = $this->value($data, 'message', 'message', []);
        if (!\is_array($message)) {
            throw new InvalidArgumentException('Serialized envelope message field must be an array.');
        }

        return new SerializedEnvelope(
            new SerializedMessage(
                (string) $this->value($message, 'name', 'name'),
                (string) $this->value($message, 'contentType', 'content_type'),
                (string) $this->value($message, 'payload', 'payload'),
                $this->headers($this->value($message, 'headers', 'headers', [])),
                (string) $this->value(
                    $message,
                    'payloadEncoding',
                    'payload_encoding',
                    SerializedMessage::PAYLOAD_ENCODING_PLAIN,
                ),
            ),
            $this->headers($this->value($data, 'headers', 'headers', [])),
            (string) $this->value($data, 'messageId', 'message_id'),
            $this->nullableString($this->value($data, 'causationId', 'causation_id')),
            (string) $this->value($data, 'correlationId', 'correlation_id'),
            (string) $this->value($data, 'flow', 'flow'),
            $this->nullableString($this->value($data, 'bindingId', 'binding_id')),
            new DateTimeImmutable((string) $this->value($data, 'createdAt', 'created_at')),
            (int) $this->value($data, 'schemaVersion', 'schema_version', SerializedEnvelope::SCHEMA_VERSION),
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private function value(array $data, string $camelCase, string $snakeCase, mixed $default = null): mixed
    {
        if (\array_key_exists($camelCase, $data)) {
            return $data[$camelCase];
        }

        if (\array_key_exists($snakeCase, $data)) {
            return $data[$snakeCase];
        }

        return $default;
    }

    /**
     * @return array<string, mixed>
     */
    private function headers(mixed $headers): array
    {
        if (!\is_array($headers)) {
            return [];
        }

        return $headers;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return (string) $value;
    }
}
