<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Execution;

/**
 * @template TResult = mixed
 * @implements HandlerResultInterface<TResult>
 */
final class HandlerResult implements HandlerResultInterface
{
    /**
     * @param class-string $actionClass
     * @param TResult $result
     */
    public function __construct(
        private readonly string $bindingId,
        private readonly string $actionClass,
        private readonly mixed $result = null,
        private readonly ?\Throwable $error = null,
    ) {
    }

    public static function success(string $bindingId, string $actionClass, mixed $result): self
    {
        return new self($bindingId, $actionClass, $result);
    }

    public static function failure(string $bindingId, string $actionClass, \Throwable $error): self
    {
        return new self($bindingId, $actionClass, null, $error);
    }

    public function bindingId(): string
    {
        return $this->bindingId;
    }

    public function actionClass(): string
    {
        return $this->actionClass;
    }

    public function result(): mixed
    {
        if ($this->error !== null) {
            throw $this->error;
        }

        return $this->result;
    }

    public function error(): ?\Throwable
    {
        return $this->error;
    }

    public function isSuccessful(): bool
    {
        return $this->error === null;
    }
}
