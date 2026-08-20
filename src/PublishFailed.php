<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus;

use RuntimeException;

final class PublishFailed extends RuntimeException
{
    public function __construct(
        private readonly PublishResult $result,
        ?\Throwable $previous = null,
    ) {
        parent::__construct('Message publish failed.', 0, $previous);
    }

    public function result(): PublishResult
    {
        return $this->result;
    }
}
