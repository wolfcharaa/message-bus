<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\MessageBus;

use DateTimeImmutable;
use Wolfcharaa\MessageBus\Message\Message;

/**
 * @api
 * @template-covariant TResult = mixed
 * @template-covariant TMessage of Message<TResult> = Message<mixed>
 */
final class Envelope implements \JsonSerializable
{
    /**
     * @param TMessage $message
     * @param non-empty-string $messageId
     * @param ?non-empty-string $causationId
     * @param ?non-empty-string $correlationId
     * @param array<string, mixed> $headers
     */
    public function __construct(
        public readonly Message $message,
        public readonly string $messageId,
        public readonly ?string $causationId = null,
        public readonly ?string $correlationId = null,
        public readonly ?DateTimeImmutable $timestamp = null,
        public readonly array $headers = [],
    ) {
    }

    public function jsonSerialize(): array
    {
        return [
            'message' => [
                'class' => $this->message::class,
                'values' => get_object_vars($this->message)
            ],
            'messageId' => $this->messageId,
            'causationId' => $this->causationId,
            'correlationId' => $this->correlationId,
            'timestamp' => ($this->timestamp ?? new DateTimeImmutable())->format('Y-m-d H:i:s'),
            'headers' => $this->headers,
        ];
    }

    public static function restore(array $data): Envelope
    {
        return new self(
            message: new $data['message']['class'](...$data['message']['values']),
            messageId: $data['messageId'],
            causationId: $data['causationId'],
            correlationId: $data['correlationId'],
            timestamp: DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $data['timestamp']),
            headers: $data['headers'],
        );
    }
}
