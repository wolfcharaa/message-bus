<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus;

use DateTimeImmutable;
use Wolfcharaa\MessageBus\Message\Message;

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
                'values' => \get_object_vars($this->message),
                'serialize' => base64_encode(serialize($this->message))
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
            self::resolveClass($data['message']),
            $data['messageId'],
            $data['causationId'],
            $data['correlationId'],
            \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $data['timestamp']),
            $header
        );
    }

    /**
     * Восстанавивает класс из сериализованного состояния. 
     * @param array{serialize: string, class: string, values: array} $message
     */
    public static function resolveClass(array $message):object {
        if (!empty($message['serialize'])) {
            $rawClass = base64_decode($message['serialize']);
            $class = unserialize($rawClass, ['allowed_classes' => true]);
            if (is_object($class)) {
                return $class;
            }
        }
        $class = new $message['class'](...\array_values($message['values']));
        return $class;
    }
}
