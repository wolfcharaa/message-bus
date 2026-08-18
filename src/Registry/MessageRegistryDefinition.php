<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Registry;

use Wolfcharaa\MessageBus\Flow\FlowRegistry;

final class MessageRegistryDefinition
{
    /**
     * @param array<string, list<string>> $messages
     * @param array<string, HandlerBindingDefinition> $bindings
     * @param array<string, class-string> $aliases
     * @param array<class-string, string> $messageNames
     */
    public function __construct(
        public readonly int $schemaVersion,
        public readonly string $libraryVersion,
        public readonly string $generatedAt,
        public readonly string $sourceHash,
        public readonly FlowRegistry $flows,
        public readonly array $messages,
        public readonly array $bindings,
        public readonly array $aliases,
        public readonly array $messageNames,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schemaVersion' => $this->schemaVersion,
            'libraryVersion' => $this->libraryVersion,
            'generatedAt' => $this->generatedAt,
            'sourceHash' => $this->sourceHash,
            'flows' => $this->flows->toArray(),
            'messages' => $this->messages,
            'bindings' => \array_map(
                static fn (HandlerBindingDefinition $binding): array => $binding->toArray(),
                $this->bindings,
            ),
            'aliases' => $this->aliases,
            'messageNames' => $this->messageNames,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        if (($data['schemaVersion'] ?? null) !== 4) {
            throw new RegistryCompilationException('Unsupported message registry schema version.');
        }

        $flows = \array_map(
            static fn (array $flow): \Wolfcharaa\MessageBus\Flow\FlowDefinition => \Wolfcharaa\MessageBus\Flow\FlowDefinition::fromArray($flow),
            $data['flows'],
        );

        $bindings = [];
        foreach ($data['bindings'] as $bindingId => $binding) {
            $bindings[$bindingId] = HandlerBindingDefinition::fromArray($binding);
        }

        return new self(
            $data['schemaVersion'],
            $data['libraryVersion'],
            $data['generatedAt'],
            $data['sourceHash'],
            new FlowRegistry(...$flows),
            $data['messages'],
            $bindings,
            $data['aliases'] ?? [],
            $data['messageNames'] ?? [],
        );
    }
}
