<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Registry\Metadata;

use InvalidArgumentException;

final readonly class RegistryOwner
{
    public string $kind;

    public string $id;

    public function __construct(
        string $kind,
        string $id,
    ) {
        $this->kind = $this->normalizeSlug($kind, 'kind');
        $this->id = $this->normalizeSlug($id, 'id');
    }

    public static function core(string $id): self
    {
        return new self('core', $id);
    }

    public static function feature(string $id): self
    {
        return new self('feature', $id);
    }

    public static function app(string $id): self
    {
        return new self('app', $id);
    }

    /** @param array{kind: string, id: string} $data */
    public static function fromArray(array $data): self
    {
        return new self(
            self::requireString($data, 'kind'),
            self::requireString($data, 'id'),
        );
    }

    /** @return array{kind: string, id: string} */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'id' => $this->id,
        ];
    }

    public function equals(self $other): bool
    {
        return $this->kind === $other->kind && $this->id === $other->id;
    }

    private function normalizeSlug(string $value, string $field): string
    {
        $value = \strtolower(\trim($value));
        if ($value === '' || !\preg_match('/^[a-z0-9][a-z0-9._-]*$/', $value)) {
            throw new InvalidArgumentException(\sprintf('Registry owner %s must be a non-empty lowercase ASCII slug.', $field));
        }

        return $value;
    }

    /** @param array<string, mixed> $data */
    private static function requireString(array $data, string $key): string
    {
        if (!isset($data[$key]) || !\is_string($data[$key])) {
            throw new InvalidArgumentException(\sprintf('Registry owner field `%s` must be a string.', $key));
        }

        return $data[$key];
    }
}
