<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Registry;

use Wolfcharaa\MessageBus\Context\MessageContext;
use Wolfcharaa\MessageBus\Contract\Message;

/**
 *  Self-hashed portable compiled-registry artifact.
 *
 * @api tooling
 */
final readonly class RegistryArtifact
{
    private const int SCHEMA = 1;

    /** @param list<array<string, bool|int|string|null>> $bindings */
    public function __construct(
        public string $registryFingerprint,
        public array $bindings,
        public ?string $applicationBuildId,
        public string $contentHash,
    ) {
        if ($this->applicationBuildId !== null
            && preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._:-]{0,127}$/D', $this->applicationBuildId) !== 1) {
            throw new \InvalidArgumentException('Registry artifact application build ID is invalid.');
        }
    }

    public static function fromRegistry(
        CompiledRegistry $registry,
        ?string $applicationBuildId = null,
    ): self {
        $bindings = [];
        foreach ($registry->bindings() as $messageClass => $messageBindings) {
            foreach ($messageBindings as $binding) {
                $bindings[] = [
                    'id' => $binding->id,
                    'message_class' => $messageClass,
                    'role' => $binding->role->value,
                    'handler_class' => $binding->handlerClass,
                    'handler_method' => $binding->method,
                    'context_class' => $binding->contextClass,
                    'subscription_id' => $binding->subscriptionId,
                ];
            }
        }
        usort($bindings, static fn(array $a, array $b): int => [
            $a['message_class'], $a['id'],
        ] <=> [
            $b['message_class'], $b['id'],
        ]);
        $hash = hash('sha256', self::canonical($registry->fingerprint, $bindings, $applicationBuildId));

        return new self($registry->fingerprint, $bindings, $applicationBuildId, $hash);
    }

    public function toJson(): string
    {
        return json_encode([
            'schema' => self::SCHEMA,
            'registry_fingerprint' => $this->registryFingerprint,
            'bindings' => $this->bindings,
            'application_build_id' => $this->applicationBuildId,
            'content_hash' => $this->contentHash,
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    }

    public static function fromJson(string $json): self
    {
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!\is_array($decoded) || array_is_list($decoded)) {
            throw new \UnexpectedValueException('Compiled registry artifact has an unsupported shape.');
        }
        $data = self::object($decoded);
        if (($data['schema'] ?? null) !== self::SCHEMA) {
            throw new \UnexpectedValueException('Compiled registry artifact has an unsupported schema.');
        }
        $artifact = new self(
            self::string($data, 'registry_fingerprint'),
            self::bindings($data),
            self::nullableString($data, 'application_build_id'),
            self::string($data, 'content_hash'),
        );
        $expected = hash('sha256', self::canonical(
            $artifact->registryFingerprint,
            $artifact->bindings,
            $artifact->applicationBuildId,
        ));
        if (!hash_equals($expected, $artifact->contentHash)) {
            throw new \UnexpectedValueException('Compiled registry artifact hash does not match its content.');
        }

        return $artifact;
    }

    public function registry(): CompiledRegistry
    {
        $bindings = [];
        foreach ($this->bindings as $row) {
            foreach ([
                'id', 'message_class', 'role', 'handler_class', 'handler_method',
            ] as $required) {
                if (!\is_string($row[$required] ?? null)) {
                    throw new \UnexpectedValueException('Compiled registry binding is incomplete.');
                }
            }
            $messageClass = self::string($row, 'message_class');
            $handlerClass = self::string($row, 'handler_class');
            $contextClass = self::nullableString($row, 'context_class');
            if (!class_exists($messageClass) || !is_a($messageClass, Message::class, true)
                || !class_exists($handlerClass)
                || ($contextClass !== null && !is_a($contextClass, MessageContext::class, true))) {
                throw new \UnexpectedValueException('Compiled registry artifact references an unavailable symbol.');
            }
            $bindings[$messageClass][] = new HandlerBinding(
                self::string($row, 'id'),
                $messageClass,
                MessageRole::from(self::string($row, 'role')),
                $handlerClass,
                self::string($row, 'handler_method'),
                $contextClass,
                self::nullableString($row, 'subscription_id'),
            );
        }

        return new CompiledRegistry($bindings, $this->registryFingerprint);
    }

    public function assertApplicationBuildId(string $expected): void
    {
        if ($this->applicationBuildId === null || !hash_equals($this->applicationBuildId, $expected)) {
            throw new \UnexpectedValueException('Compiled registry artifact application build ID does not match.');
        }
    }

    /** @param list<array<string, bool|int|string|null>> $bindings */
    private static function canonical(
        string $fingerprint,
        array $bindings,
        ?string $applicationBuildId,
    ): string {
        return json_encode([
            'schema' => self::SCHEMA,
            'registry_fingerprint' => $fingerprint,
            'bindings' => $bindings,
            'application_build_id' => $applicationBuildId,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /** @param array<string, mixed> $data */
    private static function string(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (!\is_string($value)) {
            throw new \UnexpectedValueException('Compiled registry field must be a string: ' . $key);
        }

        return $value;
    }

    /** @param array<string, mixed> $data */
    private static function nullableString(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;
        if ($value !== null && !\is_string($value)) {
            throw new \UnexpectedValueException('Compiled registry field must be string|null: ' . $key);
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $data
     * @return list<array<string, bool|int|string|null>>
     */
    private static function bindings(array $data): array
    {
        $value = $data['bindings'] ?? null;
        if (!\is_array($value) || !array_is_list($value)) {
            throw new \UnexpectedValueException('Compiled registry bindings must be a list.');
        }
        $bindings = [];
        foreach ($value as $row) {
            if (!\is_array($row) || array_is_list($row)) {
                throw new \UnexpectedValueException('Compiled registry binding must be an object.');
            }
            $binding = [];
            foreach ($row as $key => $field) {
                if (!\is_string($key)
                    || (!\is_bool($field) && !\is_int($field) && !\is_string($field) && $field !== null)) {
                    throw new \UnexpectedValueException('Compiled registry binding contains a non-scalar field.');
                }
                $binding[$key] = $field;
            }
            $bindings[] = $binding;
        }

        return $bindings;
    }

    /**
     * @param array<mixed> $value
     * @return array<string, mixed>
     */
    private static function object(array $value): array
    {
        $object = [];
        foreach ($value as $key => $field) {
            if (!\is_string($key)) {
                throw new \UnexpectedValueException('Compiled registry object has a non-string key.');
            }
            $object[$key] = $field;
        }

        return $object;
    }
}

/**
 *  Public tooling root for atomic artifact writes and verified loads.
 *
 * @api tooling
 */
final readonly class RegistryArtifactStore
{
    public function write(RegistryArtifact $artifact, string $path): void
    {
        $directory = \dirname($path);
        if (!is_dir($directory) || !is_writable($directory)) {
            throw new \RuntimeException('Registry artifact directory is not writable: ' . $directory);
        }
        $temporary = $path . '.tmp.' . bin2hex(random_bytes(6));
        if (file_put_contents($temporary, $artifact->toJson(), LOCK_EX) === false
            || !rename($temporary, $path)) {
            @unlink($temporary);

            throw new \RuntimeException('Unable to atomically write registry artifact.');
        }
    }

    public function load(string $path, ?string $expectedApplicationBuildId = null): RegistryArtifact
    {
        $json = file_get_contents($path);
        if (!\is_string($json)) {
            throw new \RuntimeException('Unable to read compiled registry artifact: ' . $path);
        }

        $artifact = RegistryArtifact::fromJson($json);
        if ($expectedApplicationBuildId !== null) {
            $artifact->assertApplicationBuildId($expectedApplicationBuildId);
        }

        return $artifact;
    }
}
