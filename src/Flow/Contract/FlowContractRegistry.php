<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Flow\Contract;

use BackedEnum;
use InvalidArgumentException;

final readonly class FlowContractRegistry
{
    /** @var array<string, FlowContract> */
    private array $contracts;

    public function __construct(FlowContract ...$contracts)
    {
        $indexed = [];
        foreach ($contracts as $contract) {
            if (isset($indexed[$contract->flow])) {
                throw new InvalidArgumentException(\sprintf('Flow contract for `%s` is already registered.', $contract->flow));
            }

            $indexed[$contract->flow] = $contract;
        }

        $this->contracts = $indexed;
    }

    public function get(string|BackedEnum $flow): ?FlowContract
    {
        $key = $flow instanceof BackedEnum ? (string) $flow->value : $flow;

        return $this->contracts[$key] ?? null;
    }

    /** @return array<string, FlowContract> */
    public function all(): array
    {
        return $this->contracts;
    }
}
