<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Serialization;

use InvalidArgumentException;

final class SerializedMessage
{
    public const PAYLOAD_ENCODING_PLAIN = 'plain';
    public const PAYLOAD_ENCODING_BASE64 = 'base64';

    public function __construct(
        public readonly string $name,
        public readonly string $contentType,
        public readonly string $payload,
        /** @var array<string, mixed> */
        public readonly array $headers = [],
        public readonly string $payloadEncoding = self::PAYLOAD_ENCODING_PLAIN,
    ) {
        if (!\in_array($this->payloadEncoding, [self::PAYLOAD_ENCODING_PLAIN, self::PAYLOAD_ENCODING_BASE64], true)) {
            throw new InvalidArgumentException(\sprintf('Payload encoding `%s` is not supported.', $this->payloadEncoding));
        }
    }
}
