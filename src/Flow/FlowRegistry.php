<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Flow;

use BackedEnum;
use InvalidArgumentException;

final class FlowRegistry
{
    /** @var array<string, FlowDefinition> */
    private array $flows = [];

    public function __construct(FlowDefinition ...$flows)
    {
        if ($flows === []) {
            $flows = [FlowDefinition::sync('default')];
        }

        foreach ($flows as $flow) {
            $this->flows[$flow->key] = $flow;
        }
    }

    public function get(string|BackedEnum $key): FlowDefinition
    {
        $key = $key instanceof BackedEnum ? (string) $key->value : $key;

        return $this->flows[$key] ?? throw new InvalidArgumentException(\sprintf(
            'Message flow `%s` is not registered.',
            $key,
        ));
    }

    /** @return array<string, FlowDefinition> */
    public function all(): array
    {
        return $this->flows;
    }

    /** @return array<string, array<string, mixed>> */
    public function toArray(): array
    {
        return \array_map(static fn (FlowDefinition $flow): array => $flow->toArray(), $this->flows);
    }
}
