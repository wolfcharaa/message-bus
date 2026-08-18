<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Execution;

use BackedEnum;

/**
 * @template TResult = mixed
 */
interface HandlerExecutionResultInterface
{
    /** @return list<HandlerResultInterface<mixed>> */
    public function all(): array;

    /**
     * @template TBindingResult = mixed
     * @param string|BackedEnum $bindingId
     * @return TBindingResult
     */
    public function get(string|BackedEnum $bindingId): mixed;

    /**
     * @template TActionResult = mixed
     * @param class-string $actionClass
     * @return TActionResult
     */
    public function getByAction(string $actionClass): mixed;

    /** @return list<HandlerResultInterface<mixed>> */
    public function successful(): array;

    /** @return list<HandlerResultInterface<mixed>> */
    public function failed(): array;

    public function hasFailures(): bool;
}
