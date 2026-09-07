<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Registry;

final readonly class RegistryDiagnostic
{
    public function __construct(
        public RegistryDiagnosticSeverity $severity,
        public string $code,
        public string $message,
        public ?RegistryDiagnosticOrigin $origin = null,
        public ?RegistryDiagnosticTarget $target = null,
        public ?string $hint = null,
    ) {
    }

    public static function error(
        string $code,
        string $message,
        ?RegistryDiagnosticOrigin $origin = null,
        ?RegistryDiagnosticTarget $target = null,
        ?string $hint = null,
    ): self {
        return new self(RegistryDiagnosticSeverity::Error, $code, $message, $origin, $target, $hint);
    }

    public static function warning(
        string $code,
        string $message,
        ?RegistryDiagnosticOrigin $origin = null,
        ?RegistryDiagnosticTarget $target = null,
        ?string $hint = null,
    ): self {
        return new self(RegistryDiagnosticSeverity::Warning, $code, $message, $origin, $target, $hint);
    }

    public static function info(
        string $code,
        string $message,
        ?RegistryDiagnosticOrigin $origin = null,
        ?RegistryDiagnosticTarget $target = null,
        ?string $hint = null,
    ): self {
        return new self(RegistryDiagnosticSeverity::Info, $code, $message, $origin, $target, $hint);
    }

    public function isError(): bool
    {
        return $this->severity === RegistryDiagnosticSeverity::Error;
    }

    public function isWarning(): bool
    {
        return $this->severity === RegistryDiagnosticSeverity::Warning;
    }
}
