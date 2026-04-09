<?php

declare(strict_types=1);

namespace App\MessageBus;

use DateTimeImmutable;
use App\MessageBus\Message\Message;

/**
 * @api
 * @template-covariant TResult = mixed
 * @template-covariant TMessage of Message<TResult>|object = Message<mixed>
 */
final class Envelope implements \JsonSerializable
{
    /**
     * @var TMessage $message
     */
    public object $message;
    /**
     * @var non-empty-string $messageId
     */
    public string $messageId;
    /**
     * @var ?non-empty-string $causationId
     */
    public ?string $causationId = null;
    /**
     * @var ?non-empty-string $correlationId
     */
    public ?string $correlationId = null;
    /**
     * @var DateTimeImmutable|null $timestamp
     */
    public ?DateTimeImmutable $timestamp = null;
    /**
     * @var Header $headers
     */
    public Header $header;

    public function __construct(
        object $message,
        string $messageId,
        ?string $causationId = null,
        ?string $correlationId = null,
        ?DateTimeImmutable $timestamp = null,
        ?Header $header = null
    ) {
        $this->message = $message;
        $this->messageId = $messageId;
        $this->causationId = $causationId;
        $this->correlationId = $correlationId;
        $this->timestamp = $timestamp;
        $this->header = $header ?? new Header();
    }

    public function jsonSerialize(): array
    {
        return [
            'message' => [
                'class' => \get_class($this->message),
                'values' => \get_object_vars($this->message)
            ],
            'messageId' => $this->messageId,
            'causationId' => $this->causationId,
            'correlationId' => $this->correlationId,
            'timestamp' => ($this->timestamp ?? new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            'headers' => $this->header->jsonSerialize(),
        ];
    }

    public static function restore(array $data, ?Header $header = null): Envelope
    {
        return new self(
            new $data['message']['class'](...\array_values($data['message']['values'])),
            $data['messageId'],
            $data['causationId'],
            $data['correlationId'],
            \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $data['timestamp']),
            $header
        );
    }
}
