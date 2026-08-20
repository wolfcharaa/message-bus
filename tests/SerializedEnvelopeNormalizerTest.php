<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Tests;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Wolfcharaa\MessageBus\Envelope\SerializedEnvelope;
use Wolfcharaa\MessageBus\Envelope\SerializedEnvelopeNormalizer;
use Wolfcharaa\MessageBus\Serialization\SerializedMessage;

final class SerializedEnvelopeNormalizerTest extends TestCase
{
    public function testToArrayIncludesPayloadEncoding(): void
    {
        $normalizer = new SerializedEnvelopeNormalizer();
        $envelope = $this->envelope(new SerializedMessage(
            'demo.message',
            'application/octet-stream',
            'AQID',
            payloadEncoding: SerializedMessage::PAYLOAD_ENCODING_BASE64,
        ));

        $data = $normalizer->toArray($envelope);

        self::assertSame(SerializedMessage::PAYLOAD_ENCODING_BASE64, $data['message']['payloadEncoding']);
    }

    public function testFromArrayReadsCamelCasePayloadEncoding(): void
    {
        $normalizer = new SerializedEnvelopeNormalizer();

        $envelope = $normalizer->fromArray([
            'schemaVersion' => 1,
            'message' => [
                'name' => 'demo.message',
                'contentType' => 'application/octet-stream',
                'payload' => 'AQID',
                'payloadEncoding' => SerializedMessage::PAYLOAD_ENCODING_BASE64,
                'headers' => [],
            ],
            'headers' => [],
            'messageId' => 'message-1',
            'causationId' => null,
            'correlationId' => 'correlation-1',
            'flow' => 'default',
            'bindingId' => null,
            'createdAt' => '2026-08-20T10:00:00+03:00',
        ]);

        self::assertSame(SerializedMessage::PAYLOAD_ENCODING_BASE64, $envelope->message->payloadEncoding);
    }

    public function testFromArrayReadsLegacySnakeCaseAndDefaultsPayloadEncodingToPlain(): void
    {
        $normalizer = new SerializedEnvelopeNormalizer();

        $envelope = $normalizer->fromArray([
            'schema_version' => 1,
            'message' => [
                'name' => 'demo.message',
                'content_type' => 'application/json',
                'payload' => '{}',
                'headers' => [],
            ],
            'headers' => [],
            'message_id' => 'message-1',
            'causation_id' => null,
            'correlation_id' => 'correlation-1',
            'flow' => 'default',
            'binding_id' => null,
            'created_at' => '2026-08-20T10:00:00+03:00',
        ]);

        self::assertSame('application/json', $envelope->message->contentType);
        self::assertSame(SerializedMessage::PAYLOAD_ENCODING_PLAIN, $envelope->message->payloadEncoding);
        self::assertSame('message-1', $envelope->messageId);
    }

    private function envelope(SerializedMessage $message): SerializedEnvelope
    {
        return new SerializedEnvelope(
            $message,
            [],
            'message-1',
            null,
            'correlation-1',
            'default',
            null,
            new DateTimeImmutable('2026-08-20 10:00:00+03:00'),
        );
    }
}
