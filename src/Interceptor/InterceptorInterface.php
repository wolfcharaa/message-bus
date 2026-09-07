<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Interceptor;

use Wolfcharaa\MessageBus\Context\MessageContextInterface;

interface InterceptorInterface
{
    public function __invoke(MessageContextInterface $context, PipelineInterface $pipeline): mixed;
}
