<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Bootstrap\ServiceReference;

use RuntimeException;

final class ServiceReferenceResolutionFailed extends RuntimeException
{
    public function __construct(
        public readonly ServiceReference $reference,
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }

    public static function notFound(ServiceReference $reference, ?\Throwable $previous = null): self
    {
        return new self($reference, \sprintf('Service reference `%s` was not found.', $reference->id), $previous);
    }

    public static function containerFailure(ServiceReference $reference, \Throwable $previous): self
    {
        return new self($reference, \sprintf('Service reference `%s` failed during container resolution.', $reference->id), $previous);
    }

    public static function invalidType(ServiceReference $reference, string $actualType): self
    {
        return new self($reference, \sprintf(
            'Service reference `%s` must resolve to `%s`, got `%s`.',
            $reference->id,
            $reference->expectedType,
            $actualType,
        ));
    }
}
