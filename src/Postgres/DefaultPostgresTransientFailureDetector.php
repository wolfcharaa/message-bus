<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Postgres;

use PDOException;

final class DefaultPostgresTransientFailureDetector implements PostgresTransientFailureDetectorInterface
{
    private const TRANSIENT_SQLSTATES = [
        '08006',
        '08003',
        '57P01',
        '57P02',
        '57P03',
    ];

    private const MESSAGE_PATTERNS = [
        'server closed the connection unexpectedly',
        'terminating connection due to administrator command',
        'connection not open',
        'ssl syscall error',
        'could not connect to server',
        'general error: 7',
    ];

    public function isTransient(\Throwable $error): bool
    {
        return $this->reason($error) !== null;
    }

    public function reason(\Throwable $error): ?string
    {
        $sqlState = $this->sqlState($error);
        if ($sqlState !== null && \in_array($sqlState, self::TRANSIENT_SQLSTATES, true)) {
            return 'sqlstate.' . $sqlState;
        }

        $message = \strtolower($error->getMessage());
        foreach (self::MESSAGE_PATTERNS as $pattern) {
            if (\str_contains($message, $pattern)) {
                return 'message.' . \str_replace(' ', '_', $pattern);
            }
        }

        return null;
    }

    private function sqlState(\Throwable $error): ?string
    {
        if ($error instanceof PDOException && isset($error->errorInfo[0]) && \is_string($error->errorInfo[0])) {
            return $error->errorInfo[0];
        }

        $code = $error->getCode();
        if (\is_string($code) && \preg_match('/^[A-Z0-9]{5}$/', $code) === 1) {
            return $code;
        }

        if (\preg_match('/SQLSTATE\[([A-Z0-9]{5})]/', $error->getMessage(), $matches) === 1) {
            return $matches[1];
        }

        return null;
    }
}
