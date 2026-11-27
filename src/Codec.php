<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Codec;

use Wolfcharaa\MessageBus\Attribute\MessageName;
use Wolfcharaa\MessageBus\Attribute\Payload;
use Wolfcharaa\MessageBus\Contract\Message;

/**
 *  Canonical versioned payload at the durable boundary.
 *
 * @api application
 */
final readonly class EncodedPayload
{
    public function __construct(
        public string $messageName,
        public int $version,
        public string $codec,
        public string $bytes,
        public ?string $objectGraphFingerprint = null,
    ) {
        if ($this->messageName === '') {
            throw new \InvalidArgumentException('EncodedPayload messageName must not be empty.');
        }
        if ($this->version < 1) {
            throw new \InvalidArgumentException('EncodedPayload version must be at least 1.');
        }
        if ($this->codec === '') {
            throw new \InvalidArgumentException('EncodedPayload codec must not be empty.');
        }
        if ($this->objectGraphFingerprint !== null
            && preg_match('/^[a-f0-9]{64}$/D', $this->objectGraphFingerprint) !== 1) {
            throw new \InvalidArgumentException(
                'EncodedPayload objectGraphFingerprint must be a lowercase SHA-256 digest.',
            );
        }
    }

    public function contentType(): string
    {
        return match ($this->codec) {
            'json' => 'application/json',
            'php-native' => 'application/vnd.php.serialized',
            default => 'application/octet-stream',
        };
    }
}

/**
 * Explicit codec for one message type and payload version.
 *
 * @template-covariant TMessage of Message
 * @api spi
 */
interface MessageCodec
{
    /** @param Message<mixed> $message */
    public function encode(Message $message): string;

    /** @return TMessage */
    public function decode(string $payload): Message;
}

/**
 *  Transforms one historical decoded JSON shape into the next version.
 *
 * @api spi
 */
interface MessageUpcaster
{
    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function upcast(array $payload): array;
}

/**
 * JSON decoder that applies every explicit shape transition before construction.
 *
 * @template TMessage of Message
 * @implements MessageCodec<TMessage>
 * @api adapter
 */
final readonly class UpcastingJsonMessageCodec implements MessageCodec
{
    /**
     * @param class-string<TMessage> $messageClass
     * @param list<MessageUpcaster> $upcasters ordered from stored to current version
     */
    public function __construct(
        private string $messageClass,
        private array $upcasters,
    ) {}

    /** @param Message<mixed> $message */
    public function encode(Message $message): string
    {
        if (!$message instanceof $this->messageClass) {
            throw new \InvalidArgumentException('Upcasting JSON codec received an unexpected message type.');
        }

        return json_encode(get_object_vars($message), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    public function decode(string $payload): Message
    {
        $values = $this->jsonObject($payload);
        foreach ($this->upcasters as $upcaster) {
            $values = $upcaster->upcast($values);
        }

        return new ($this->messageClass)(...$values);
    }

    /** @return array<string, mixed> */
    private function jsonObject(string $payload): array
    {
        $decoded = json_decode($payload, false, 512, JSON_THROW_ON_ERROR);
        if (!$decoded instanceof \stdClass) {
            throw new \UnexpectedValueException('JSON message payload must decode to an object.');
        }
        $values = get_object_vars($decoded);
        $object = [];
        foreach ($values as $key => $value) {
            if (!\is_string($key)) {
                throw new \UnexpectedValueException('JSON message payload contains a non-string key.');
            }
            $object[$key] = $value;
        }

        return $object;
    }
}

/**
 *  Exact per-message/per-version codec registry.
 *
 * @api application
 */
final readonly class MessageCodecs
{
    /**
     * @param array<class-string<Message<mixed>>, MessageCodec<Message<mixed>>> $encoders
     * @param array<string, array<int, MessageCodec<Message<mixed>>>> $decoders
     */
    public function __construct(
        private array $encoders,
        private array $decoders,
    ) {}

    /** @param Message<mixed> $message */
    public function encode(Message $message): EncodedPayload
    {
        $reflection = new \ReflectionClass($message);
        $nameAttribute = $reflection->getAttributes(MessageName::class)[0] ?? null;
        if ($nameAttribute === null) {
            throw new \InvalidArgumentException('Durable message requires #[MessageName]: ' . $message::class);
        }
        $name = $nameAttribute->newInstance()->value;
        $payload = ($reflection->getAttributes(Payload::class)[0] ?? null)?->newInstance()
            ?? new Payload();
        $codec = $this->encoders[$message::class]
            ?? throw new \LogicException('No encoder is registered for ' . $message::class . '.');

        return new EncodedPayload(
            $name,
            $payload->version,
            $payload->codec,
            $codec->encode($message),
            $codec instanceof PhpNativeMessageCodec ? $codec->compatibilityFingerprint() : null,
        );
    }

    /** @param class-string<Message<mixed>> $messageClass */
    public function canEncode(string $messageClass): bool
    {
        return isset($this->encoders[$messageClass]);
    }

    /** @return Message<mixed> */
    public function decode(EncodedPayload $payload): Message
    {
        $codec = $this->decoders[$payload->messageName][$payload->version]
            ?? throw new \LogicException(\sprintf(
                'No decoder is registered for %s v%d.',
                $payload->messageName,
                $payload->version,
            ));

        return $codec->decode($payload->bytes);
    }
}

/**
 * JSON codec for immutable DTO messages with public constructor properties.
 *
 * @template TMessage of Message
 * @implements MessageCodec<TMessage>
 * @api adapter
 */
final readonly class JsonMessageCodec implements MessageCodec
{
    /** @param class-string<TMessage> $messageClass */
    public function __construct(private string $messageClass) {}

    /** @param Message<mixed> $message */
    public function encode(Message $message): string
    {
        if (!$message instanceof $this->messageClass) {
            throw new \InvalidArgumentException('JSON codec received an unexpected message type.');
        }

        return json_encode(get_object_vars($message), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    public function decode(string $payload): Message
    {
        $decoded = json_decode($payload, false, 512, JSON_THROW_ON_ERROR);
        if (!$decoded instanceof \stdClass) {
            throw new \UnexpectedValueException('JSON message payload must decode to an object.');
        }
        $values = get_object_vars($decoded);
        foreach (array_keys($values) as $key) {
            if (!\is_string($key)) {
                throw new \UnexpectedValueException('JSON message payload contains a non-string key.');
            }
        }

        return new ($this->messageClass)(...$values);
    }
}

/**
 * Explicit bounded native-PHP codec for legacy object graphs.
 *
 * @template TMessage of Message
 * @implements MessageCodec<TMessage>
 * @api adapter
 */
final readonly class PhpNativeMessageCodec implements MessageCodec
{
    /**
     * @param class-string<TMessage> $messageClass
     */
    public function __construct(
        private string $messageClass,
        private PhpNativeTypeGraph $typeGraph,
    ) {}

    /** @param Message<mixed> $message */
    public function encode(Message $message): string
    {
        if (!$message instanceof $this->messageClass) {
            throw new \InvalidArgumentException('Native codec received an unexpected message type.');
        }

        return serialize($message);
    }

    public function decode(string $payload): Message
    {
        $message = unserialize($payload, ['allowed_classes' => $this->typeGraph->classes]);
        if (!$message instanceof $this->messageClass) {
            throw new \UnexpectedValueException('Native payload did not produce the declared message type.');
        }

        return $message;
    }

    public function compatibilityFingerprint(): string
    {
        return $this->typeGraph->fingerprint;
    }
}

/**
 *  Normalized per-message native serialization graph and compatibility hash.
 *
 * @api application
 */
final readonly class PhpNativeTypeGraph
{
    /**
     * @param list<class-string> $classes
     * @param array<class-string, array<string, list<string>>> $properties
     */
    public function __construct(
        public array $classes,
        public array $properties,
        public string $fingerprint,
    ) {}

    public function toJson(): string
    {
        return json_encode([
            'schema' => 1,
            'classes' => $this->classes,
            'properties' => $this->properties,
            'fingerprint' => $this->fingerprint,
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    }
}

/**
 *  Compiles a minimal native-PHP allowed_classes graph from declared property types.
 *
 * @api application
 */
final readonly class PhpNativeTypeGraphCompiler
{
    /**
     * @param class-string<Message<mixed>> $messageClass
     * @param array<string, list<class-string>> $concretePaths property path to concrete types
     */
    public function compile(string $messageClass, array $concretePaths = []): PhpNativeTypeGraph
    {
        if (!is_a($messageClass, Message::class, true)) {
            throw new \InvalidArgumentException('Native graph root must implement Message.');
        }
        $classes = [];
        $properties = [];
        $visiting = [];
        $this->visit($messageClass, $messageClass, $concretePaths, $classes, $properties, $visiting);
        $classList = array_keys($classes);
        sort($classList, SORT_STRING);
        ksort($properties, SORT_STRING);
        foreach ($properties as &$propertyMap) {
            ksort($propertyMap, SORT_STRING);
            foreach ($propertyMap as &$types) {
                sort($types, SORT_STRING);
            }
            unset($types);
        }
        unset($propertyMap);
        $canonical = json_encode([
            'schema' => 1,
            'classes' => $classList,
            'properties' => $properties,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return new PhpNativeTypeGraph($classList, $properties, hash('sha256', $canonical));
    }

    /**
     * @param class-string $class
     * @param array<string, list<class-string>> $overrides
     * @param array<class-string, true> $classes
     * @param array<class-string, array<string, list<string>>> $properties
     * @param array<class-string, true> $visiting
     */
    private function visit(
        string $class,
        string $path,
        array $overrides,
        array &$classes,
        array &$properties,
        array &$visiting,
    ): void {
        $classes[$class] = true;
        if (isset($visiting[$class])) {
            return;
        }
        $visiting[$class] = true;
        $reflection = new \ReflectionClass($class);
        foreach ($reflection->getProperties() as $property) {
            if ($property->isStatic()) {
                continue;
            }
            $propertyPath = $path . '.' . $property->getName();
            $types = $this->typeNames($property->getType());
            $concrete = $overrides[$propertyPath] ?? [];
            foreach ($types as $type) {
                if (!class_exists($type) && !interface_exists($type)) {
                    continue;
                }
                $typeReflection = new \ReflectionClass($type);
                if (($typeReflection->isInterface() || $typeReflection->isAbstract()) && $concrete === []) {
                    throw new \LogicException('Native graph path requires concrete types: ' . $propertyPath);
                }
                if (!$typeReflection->isInterface() && !$typeReflection->isAbstract()) {
                    $concrete[] = $type;
                }
            }
            $concrete = array_values(array_unique($concrete));
            $properties[$class][$property->getName()] = $concrete === [] ? $types : $concrete;
            foreach ($concrete as $implementation) {
                if (!class_exists($implementation)) {
                    throw new \LogicException('Native graph concrete type does not exist: ' . $implementation);
                }
                $this->visit($implementation, $propertyPath, $overrides, $classes, $properties, $visiting);
            }
        }
        unset($visiting[$class]);
    }

    /** @return list<string> */
    private function typeNames(?\ReflectionType $type): array
    {
        if ($type instanceof \ReflectionNamedType) {
            return [$type->getName()];
        }
        if ($type instanceof \ReflectionUnionType || $type instanceof \ReflectionIntersectionType) {
            $names = [];
            foreach ($type->getTypes() as $part) {
                if ($part instanceof \ReflectionNamedType) {
                    $names[] = $part->getName();
                }
            }

            return $names;
        }

        return [];
    }
}
