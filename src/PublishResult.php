<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus;

final class PublishResult
{
    /**
     * @param list<PublishedExecution> $executions
     * @param list<PublishFailure> $failures
     */
    public function __construct(
        private readonly array $executions = [],
        private readonly array $failures = [],
    ) {
    }

    public static function empty(): self
    {
        return new self();
    }

    /**
     * @return list<PublishedExecution>
     */
    public function executions(): array
    {
        return $this->executions;
    }

    /**
     * @return list<PublishFailure>
     */
    public function failures(): array
    {
        return $this->failures;
    }

    public function hasFailures(): bool
    {
        return $this->failures !== [];
    }

    public function isEmpty(): bool
    {
        return $this->executions === [] && $this->failures === [];
    }

    public function merge(self $result): self
    {
        return new self(
            [...$this->executions, ...$result->executions],
            [...$this->failures, ...$result->failures],
        );
    }
}
