<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Registry;

final readonly class RegistryCompilationResult
{
    /** @param list<RegistryDiagnostic> $diagnostics */
    public function __construct(
        public ?MessageRegistryDefinition $definition = null,
        public array $diagnostics = [],
        public ?RegistryCompilationGraphContext $graphContext = null,
    ) {
    }

    public function hasDefinition(): bool
    {
        return $this->definition instanceof MessageRegistryDefinition;
    }

    /** @return list<RegistryDiagnostic> */
    public function errors(): array
    {
        return \array_values(\array_filter(
            $this->diagnostics,
            static fn (RegistryDiagnostic $diagnostic): bool => $diagnostic->isError(),
        ));
    }

    /** @return list<RegistryDiagnostic> */
    public function warnings(): array
    {
        return \array_values(\array_filter(
            $this->diagnostics,
            static fn (RegistryDiagnostic $diagnostic): bool => $diagnostic->isWarning(),
        ));
    }

    public function hasErrors(): bool
    {
        return $this->errors() !== [];
    }

    public function hasWarnings(): bool
    {
        return $this->warnings() !== [];
    }

    public function isFailure(MessageRegistryCompilerOptions $options): bool
    {
        return !$this->hasDefinition()
            || $this->hasErrors()
            || ($options->failOnWarning && $this->hasWarnings());
    }
}
