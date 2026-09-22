<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus;

/**
 * @template TResult = mixed
 */
final class FanOutResult
{
    /** @var TResult */
    public readonly mixed $dispatchResult;

    /**
     * @param TResult $dispatchResult
     */
    public function __construct(
        mixed $dispatchResult,
        public readonly PublishResult $fanOutResult,
    ) {
        $this->dispatchResult = $dispatchResult;
    }
}
