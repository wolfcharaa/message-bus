<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Discovery;

use Wolfcharaa\MessageBus\Registry\HandlerBindingDefinition;
use Wolfcharaa\MessageBus\Registry\RegistryDiagnostic;

final readonly class AttributeDiscoveryResult
{
    /**
     * @param list<HandlerBindingDefinition> $bindings
     * @param array<string, class-string> $aliases
     * @param array<class-string, string> $messageNames
     * @param list<RegistryDiagnostic> $diagnostics
     */
    public function __construct(
        public array $bindings = [],
        public array $aliases = [],
        public array $messageNames = [],
        public array $diagnostics = [],
    ) {
    }

    public function hasErrors(): bool
    {
        return $this->errors() !== [];
    }

    /** @return list<RegistryDiagnostic> */
    public function errors(): array
    {
        return \array_values(\array_filter(
            $this->diagnostics,
            static fn (RegistryDiagnostic $diagnostic): bool => $diagnostic->isError(),
        ));
    }

    /** @return array{bindings: list<HandlerBindingDefinition>, aliases: array<string, class-string>, messageNames: array<class-string, string>} */
    public function toArray(): array
    {
        return [
            'bindings' => $this->bindings,
            'aliases' => $this->aliases,
            'messageNames' => $this->messageNames,
        ];
    }
}
