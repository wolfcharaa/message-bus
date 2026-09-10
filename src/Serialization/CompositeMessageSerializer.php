<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Serialization;

use InvalidArgumentException;

final class CompositeMessageSerializer implements ContentTypeAwareMessageSerializerInterface
{
    /**
     * @var array<non-empty-string, ContentTypeAwareMessageSerializerInterface>
     */
    private array $serializersByContentType = [];

    private readonly string $writeContentType;

    /**
     * @param iterable<ContentTypeAwareMessageSerializerInterface> $serializers
     */
    public function __construct(
        iterable $serializers,
        ?string $writeContentType = null,
        private readonly string $writePayloadEncoding = SerializedMessage::PAYLOAD_ENCODING_PLAIN,
    ) {
        self::assertPayloadEncoding($writePayloadEncoding);

        $firstContentType = null;
        foreach ($serializers as $serializer) {
            if (!$serializer instanceof ContentTypeAwareMessageSerializerInterface) {
                throw new InvalidArgumentException(\sprintf(
                    'Composite message serializer expects only `%s` instances.',
                    ContentTypeAwareMessageSerializerInterface::class,
                ));
            }

            foreach ($serializer->supportedContentTypes() as $contentType) {
                if ($contentType === '') {
                    throw new InvalidArgumentException('Message serializer content type must not be empty.');
                }

                if (isset($this->serializersByContentType[$contentType])) {
                    throw new InvalidArgumentException(\sprintf(
                        'Message serializer content type `%s` is registered more than once.',
                        $contentType,
                    ));
                }

                $this->serializersByContentType[$contentType] = $serializer;
                $firstContentType ??= $contentType;
            }
        }

        if ($this->serializersByContentType === []) {
            throw new InvalidArgumentException('Composite message serializer requires at least one serializer.');
        }

        $this->writeContentType = $writeContentType ?? $firstContentType;
        if (!$this->supportsContentType($this->writeContentType)) {
            throw new InvalidArgumentException(\sprintf(
                'Message serializer write content type `%s` is not supported. Supported content types: `%s`.',
                $this->writeContentType,
                \implode('`, `', $this->supportedContentTypes()),
            ));
        }
    }

    public function serialize(object $message): SerializedMessage
    {
        $serialized = $this->serializersByContentType[$this->writeContentType]->serialize($message);

        if ($serialized->contentType !== $this->writeContentType) {
            throw new InvalidArgumentException(\sprintf(
                'Message serializer for `%s` returned payload with content type `%s`.',
                $this->writeContentType,
                $serialized->contentType,
            ));
        }

        return $this->withPayloadEncoding($serialized, $this->writePayloadEncoding);
    }

    public function deserialize(SerializedMessage $message): object
    {
        if (!$this->supportsContentType($message->contentType)) {
            throw new InvalidArgumentException(\sprintf(
                'Content type `%s` is not supported by composite message serializer. Supported content types: `%s`.',
                $message->contentType,
                \implode('`, `', $this->supportedContentTypes()),
            ));
        }

        return $this->serializersByContentType[$message->contentType]->deserialize($this->plainPayload($message));
    }

    public function supportedContentTypes(): array
    {
        return \array_keys($this->serializersByContentType);
    }

    public function supportsContentType(string $contentType): bool
    {
        return isset($this->serializersByContentType[$contentType]);
    }

    private function withPayloadEncoding(SerializedMessage $message, string $payloadEncoding): SerializedMessage
    {
        $plain = $this->plainPayload($message);

        if ($payloadEncoding === SerializedMessage::PAYLOAD_ENCODING_PLAIN) {
            return $plain;
        }

        return new SerializedMessage(
            $plain->name,
            $plain->contentType,
            \base64_encode($plain->payload),
            $plain->headers,
            SerializedMessage::PAYLOAD_ENCODING_BASE64,
        );
    }

    private function plainPayload(SerializedMessage $message): SerializedMessage
    {
        if ($message->payloadEncoding === SerializedMessage::PAYLOAD_ENCODING_PLAIN) {
            return $message;
        }

        $payload = \base64_decode($message->payload, true);
        if ($payload === false) {
            throw new InvalidArgumentException(\sprintf(
                'Message `%s` has invalid base64 payload encoding.',
                $message->name,
            ));
        }

        return new SerializedMessage(
            $message->name,
            $message->contentType,
            $payload,
            $message->headers,
            SerializedMessage::PAYLOAD_ENCODING_PLAIN,
        );
    }

    private static function assertPayloadEncoding(string $payloadEncoding): void
    {
        if (!\in_array($payloadEncoding, [
            SerializedMessage::PAYLOAD_ENCODING_PLAIN,
            SerializedMessage::PAYLOAD_ENCODING_BASE64,
        ], true)) {
            throw new InvalidArgumentException(\sprintf('Payload encoding `%s` is not supported.', $payloadEncoding));
        }
    }
}
