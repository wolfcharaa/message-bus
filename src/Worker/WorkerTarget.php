<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Worker;

use InvalidArgumentException;

final class WorkerTarget
{
    /**
     * @param list<string> $flows
     * @param list<string> $bindingIds
     * @param list<string> $bindingPatterns
     */
    public function __construct(
        public readonly ?string $workerId = null,
        public readonly ?string $workerName = null,
        public readonly ?string $workerInstanceId = null,
        public readonly ?string $workerGroup = null,
        public readonly ?string $transport = null,
        public readonly ?string $queue = null,
        public readonly array $flows = [],
        public readonly array $bindingIds = [],
        public readonly array $bindingPatterns = [],
        public readonly ?WorkerMode $mode = null,
        public readonly ?string $host = null,
        public readonly bool $all = false,
    ) {
        if ($this->all && !$this->hasOnlyAllScope()) {
            throw new InvalidArgumentException('Global worker target cannot be combined with other filters.');
        }

        if (!$this->all && !$this->hasFilters()) {
            throw new InvalidArgumentException('Worker target must define at least one filter or explicit all scope.');
        }
    }

    public static function all(): self
    {
        return new self(all: true);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'workerId' => $this->workerId,
            'workerName' => $this->workerName,
            'workerInstanceId' => $this->workerInstanceId,
            'workerGroup' => $this->workerGroup,
            'transport' => $this->transport,
            'queue' => $this->queue,
            'flows' => $this->flows,
            'bindingIds' => $this->bindingIds,
            'bindingPatterns' => $this->bindingPatterns,
            'mode' => $this->mode?->value,
            'host' => $this->host,
            'all' => $this->all,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            workerId: $data['workerId'] ?? null,
            workerName: $data['workerName'] ?? null,
            workerInstanceId: $data['workerInstanceId'] ?? null,
            workerGroup: $data['workerGroup'] ?? null,
            transport: $data['transport'] ?? null,
            queue: $data['queue'] ?? null,
            flows: $data['flows'] ?? [],
            bindingIds: $data['bindingIds'] ?? [],
            bindingPatterns: $data['bindingPatterns'] ?? [],
            mode: isset($data['mode']) ? WorkerMode::from($data['mode']) : null,
            host: $data['host'] ?? null,
            all: $data['all'] ?? false,
        );
    }

    public function matches(WorkerIdentity $identity): bool
    {
        if ($this->all) {
            return true;
        }

        if ($this->workerId !== null && $identity->workerId !== $this->workerId) {
            return false;
        }

        if ($this->workerName !== null && $identity->workerName !== $this->workerName) {
            return false;
        }

        if ($this->workerInstanceId !== null && $identity->workerInstanceId !== $this->workerInstanceId) {
            return false;
        }

        if ($this->workerGroup !== null && $identity->workerGroup !== $this->workerGroup) {
            return false;
        }

        if ($this->transport !== null && $identity->transport !== $this->transport) {
            return false;
        }

        if ($this->queue !== null && $identity->queue !== $this->queue) {
            return false;
        }

        if ($this->mode !== null && $identity->mode !== $this->mode) {
            return false;
        }

        if ($this->host !== null && $identity->host !== $this->host) {
            return false;
        }

        if (!$this->matchesListFilters($this->flows, $identity->flows)) {
            return false;
        }

        if (!$this->matchesBindingFilters($identity)) {
            return false;
        }

        return true;
    }

    public function specificityScore(): int
    {
        if ($this->all) {
            return 0;
        }

        $score = 0;
        $score += $this->workerInstanceId !== null ? 1000 : 0;
        $score += $this->workerId !== null ? 600 : 0;
        $score += $this->workerName !== null ? 500 : 0;
        $score += $this->host !== null ? 300 : 0;
        $score += $this->workerGroup !== null ? 200 : 0;
        $score += $this->mode !== null ? 100 : 0;
        $score += $this->transport !== null ? 80 : 0;
        $score += $this->queue !== null ? 80 : 0;
        $score += \count($this->flows) * 40;
        $score += \count($this->bindingIds) * 60;
        $score += \count($this->bindingPatterns) * 30;

        return $score;
    }

    private function hasFilters(): bool
    {
        return $this->workerId !== null
            || $this->workerName !== null
            || $this->workerInstanceId !== null
            || $this->workerGroup !== null
            || $this->transport !== null
            || $this->queue !== null
            || $this->flows !== []
            || $this->bindingIds !== []
            || $this->bindingPatterns !== []
            || $this->mode !== null
            || $this->host !== null;
    }

    private function hasOnlyAllScope(): bool
    {
        return !$this->hasFilters();
    }

    /**
     * @param list<string> $targetValues
     * @param list<string> $workerValues
     */
    private function matchesListFilters(array $targetValues, array $workerValues): bool
    {
        if ($targetValues === []) {
            return true;
        }

        if ($workerValues === []) {
            return true;
        }

        return \array_intersect($targetValues, $workerValues) !== [];
    }

    private function matchesBindingFilters(WorkerIdentity $identity): bool
    {
        if ($this->bindingIds === [] && $this->bindingPatterns === []) {
            return true;
        }

        if ($identity->bindingIds === [] && $identity->bindingPatterns === []) {
            return true;
        }

        if (\array_intersect($this->bindingIds, $identity->bindingIds) !== []) {
            return true;
        }

        foreach ($this->bindingIds as $bindingId) {
            foreach ($identity->bindingPatterns as $pattern) {
                if ($this->matchesPattern($pattern, $bindingId)) {
                    return true;
                }
            }
        }

        foreach ($this->bindingPatterns as $pattern) {
            foreach ($identity->bindingIds as $bindingId) {
                if ($this->matchesPattern($pattern, $bindingId)) {
                    return true;
                }
            }
        }

        return \array_intersect($this->bindingPatterns, $identity->bindingPatterns) !== [];
    }

    private function matchesPattern(string $pattern, string $value): bool
    {
        $regex = '/^' . \str_replace('\\*', '.*', \preg_quote($pattern, '/')) . '$/';

        return \preg_match($regex, $value) === 1;
    }
}
