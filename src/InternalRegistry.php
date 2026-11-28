<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Internal\Registry;

use Wolfcharaa\MessageBus\Registry\DiagnosticSeverity;
use Wolfcharaa\MessageBus\Registry\RegistryDiagnostic;

/** @internal Owns the complete, field-repeating diagnostic message invariant. */
final class RegistryDiagnosticFactory
{
    public static function error(
        string $code,
        string $summary,
        string $symbol,
        string $expected,
        string $actual,
        ?string $causeClass = null,
        ?string $sourcePath = null,
        string $subjectKind = 'symbol',
    ): RegistryDiagnostic {
        return self::create(
            $code,
            DiagnosticSeverity::Error,
            $summary,
            $symbol,
            $expected,
            $actual,
            $causeClass,
            $sourcePath,
            $subjectKind,
        );
    }

    public static function warning(
        string $code,
        string $summary,
        string $symbol,
        string $expected,
        string $actual,
        ?string $sourcePath = null,
        string $subjectKind = 'symbol',
    ): RegistryDiagnostic {
        return self::create(
            $code,
            DiagnosticSeverity::Warning,
            $summary,
            $symbol,
            $expected,
            $actual,
            null,
            $sourcePath,
            $subjectKind,
        );
    }

    private static function create(
        string $code,
        DiagnosticSeverity $severity,
        string $summary,
        string $symbol,
        string $expected,
        string $actual,
        ?string $causeClass,
        ?string $sourcePath,
        string $subjectKind,
    ): RegistryDiagnostic {
        $parts = [
            '[' . $code . '] ' . $summary,
            'Subject: ' . $symbol,
        ];
        if ($sourcePath !== null) {
            $parts[] = 'Source path: ' . $sourcePath;
        }
        $parts[] = 'Expected: ' . $expected;
        $parts[] = 'Actual: ' . $actual;
        if ($causeClass !== null) {
            $parts[] = 'Cause class: ' . $causeClass;
        }

        return new RegistryDiagnostic(
            $code,
            $severity,
            implode(' ', array_map(
                static fn(string $part): string => rtrim($part, ". \t\n\r\0\x0B") . '.',
                $parts,
            )),
            $symbol,
            $code,
            $subjectKind,
            $expected,
            $actual,
            $causeClass,
            $sourcePath,
        );
    }
}
