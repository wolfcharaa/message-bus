<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Registry;

use LogicException;
use Throwable;

final class RegistryCompilationException extends LogicException
{
    /** @var list<RegistryDiagnostic> */
    private readonly array $diagnostics;

    /** @param int|list<RegistryDiagnostic> $code */
    public function __construct(string $message = '', int|array $code = 0, ?Throwable $previous = null)
    {
        if (\is_array($code)) {
            $this->diagnostics = $code;
            parent::__construct($message, 0, $previous);

            return;
        }

        $this->diagnostics = [];
        parent::__construct($message, $code, $previous);
    }

    /** @param list<RegistryDiagnostic> $diagnostics */
    public static function fromDiagnostics(array $diagnostics, string $fallbackMessage = 'Registry compilation failed'): self
    {
        return new self(self::summary($diagnostics, $fallbackMessage), $diagnostics);
    }

    /** @return list<RegistryDiagnostic> */
    public function diagnostics(): array
    {
        return $this->diagnostics;
    }

    public function hasErrors(): bool
    {
        foreach ($this->diagnostics as $diagnostic) {
            if ($diagnostic->isError()) {
                return true;
            }
        }

        return false;
    }

    public function hasWarnings(): bool
    {
        foreach ($this->diagnostics as $diagnostic) {
            if ($diagnostic->isWarning()) {
                return true;
            }
        }

        return false;
    }

    /** @param list<RegistryDiagnostic> $diagnostics */
    private static function summary(array $diagnostics, string $fallbackMessage): string
    {
        if ($diagnostics === []) {
            return $fallbackMessage;
        }

        $first = self::firstError($diagnostics) ?? $diagnostics[0];
        $origin = self::originSummary($first->origin);

        return \sprintf(
            '%s: %d diagnostic(s), first %s %s: %s%s',
            $fallbackMessage,
            \count($diagnostics),
            $first->severity->value,
            $first->code,
            $first->message,
            $origin !== null ? ' at ' . $origin : '',
        );
    }

    /** @param list<RegistryDiagnostic> $diagnostics */
    private static function firstError(array $diagnostics): ?RegistryDiagnostic
    {
        foreach ($diagnostics as $diagnostic) {
            if ($diagnostic->isError()) {
                return $diagnostic;
            }
        }

        return null;
    }

    private static function originSummary(?RegistryDiagnosticOrigin $origin): ?string
    {
        if ($origin === null) {
            return null;
        }

        $summary = $origin->name ?? $origin->configKey ?? $origin->kind->value;
        if ($origin->file !== null) {
            $summary .= ' (' . $origin->file . ($origin->line !== null ? ':' . $origin->line : '') . ')';
        }

        return $summary;
    }
}
