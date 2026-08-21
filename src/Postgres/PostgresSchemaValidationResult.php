<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Postgres;

final class PostgresSchemaValidationResult
{
    /**
     * @param array<string, string> $requiredVersions
     * @param array<string, string|null> $currentVersions
     * @param list<PostgresSchemaValidationIssue> $issues
     */
    public function __construct(
        public readonly array $requiredVersions,
        public readonly array $currentVersions,
        public readonly array $issues,
    ) {
    }

    public function isValid(): bool
    {
        return $this->issues === [];
    }

    public function hasIssueCode(string $code): bool
    {
        foreach ($this->issues as $issue) {
            if ($issue->code === $code) {
                return true;
            }
        }

        return false;
    }
}
