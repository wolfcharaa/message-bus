<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Middleware;

/**
 * @deprecated Use Wolfcharaa\MessageBus\Interceptor\PipelineInterface for new code.
 * TODO(next-major): move the canonical pipeline implementation to Interceptor\Pipeline and keep Middleware only as a migration alias.
 */
interface PipelineInterface extends \Wolfcharaa\MessageBus\Interceptor\PipelineInterface
{
}
