<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Registry\Metadata;

use InvalidArgumentException;

final readonly class RegistrySource
{
    public string $type;

    public string $name;

    public ?string $providerClass;

    public ?string $package;

    public ?string $location;

    public function __construct(
        string $type,
        string $name,
        ?string $providerClass = null,
        ?string $package = null,
        ?string $location = null,
    ) {
        $this->type = $this->nonEmpty($type, 'type');
        $this->name = $this->nonEmpty($name, 'name');
        $this->providerClass = $this->nullable($providerClass);
        $this->package = $this->nullable($package);
        $this->location = $this->nullable($location);
    }

    public static function handler(string $handlerClass): self
    {
        return new self('handler', $handlerClass, providerClass: $handlerClass);
    }

    public static function provider(string $providerClass, ?string $package = null, ?string $location = null): self
    {
        return new self('provider', $providerClass, providerClass: $providerClass, package: $package, location: $location);
    }

    /** @param array<string, string|null> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            self::requireString($data, 'type'),
            self::requireString($data, 'name'),
            self::nullableString($data, 'providerClass'),
            self::nullableString($data, 'package'),
            self::nullableString($data, 'location'),
        );
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        return \array_filter([
            'type' => $this->type,
            'name' => $this->name,
            'providerClass' => $this->providerClass,
            'package' => $this->package,
            'location' => $this->location,
        ], static fn (?string $value): bool => $value !== null);
    }

    private function nonEmpty(string $value, string $field): string
    {
        $value = \trim($value);
        if ($value === '') {
            throw new InvalidArgumentException(\sprintf('Registry source %s must be non-empty.', $field));
        }

        return $value;
    }

    private function nullable(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = \trim($value);
        if ($value === '') {
            return null;
        }

        return $value;
    }

    /** @param array<string, mixed> $data */
    private static function requireString(array $data, string $key): string
    {
        if (!isset($data[$key]) || !\is_string($data[$key])) {
            throw new InvalidArgumentException(\sprintf('Registry source field `%s` must be a string.', $key));
        }

        return $data[$key];
    }

    /** @param array<string, mixed> $data */
    private static function nullableString(array $data, string $key): ?string
    {
        if (!\array_key_exists($key, $data) || $data[$key] === null) {
            return null;
        }

        if (!\is_string($data[$key])) {
            throw new InvalidArgumentException(\sprintf('Registry source field `%s` must be a string or null.', $key));
        }

        return $data[$key];
    }
}
