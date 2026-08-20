<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Worker;

use DateTimeImmutable;

final class WorkerIdentity
{
    /**
     * @param list<string> $flows
     * @param list<string> $bindingIds
     * @param list<string> $bindingPatterns
     */
    public function __construct(
        public readonly string $workerName,
        public readonly string $workerInstanceId,
        public readonly ?string $workerGroup,
        public readonly string $host,
        public readonly int $pid,
        public readonly DateTimeImmutable $startedAt,
        public readonly WorkerMode $mode,
        public readonly string $transport,
        public readonly string $queue,
        public readonly array $flows = [],
        public readonly array $bindingIds = [],
        public readonly array $bindingPatterns = [],
        public readonly ?string $workerId = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'workerName' => $this->workerName,
            'workerInstanceId' => $this->workerInstanceId,
            'workerGroup' => $this->workerGroup,
            'host' => $this->host,
            'pid' => $this->pid,
            'startedAt' => $this->startedAt->format(DATE_ATOM),
            'mode' => $this->mode->value,
            'transport' => $this->transport,
            'queue' => $this->queue,
            'flows' => $this->flows,
            'bindingIds' => $this->bindingIds,
            'bindingPatterns' => $this->bindingPatterns,
            'workerId' => $this->workerId,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            workerName: $data['workerName'],
            workerInstanceId: $data['workerInstanceId'],
            workerGroup: $data['workerGroup'] ?? null,
            host: $data['host'],
            pid: (int) $data['pid'],
            startedAt: new DateTimeImmutable($data['startedAt']),
            mode: WorkerMode::from($data['mode']),
            transport: $data['transport'],
            queue: $data['queue'],
            flows: $data['flows'] ?? [],
            bindingIds: $data['bindingIds'] ?? [],
            bindingPatterns: $data['bindingPatterns'] ?? [],
            workerId: $data['workerId'] ?? null,
        );
    }
}
