<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Interceptor;

use Wolfcharaa\MessageBus\Context\MessageContextInterface;
use Wolfcharaa\MessageBus\Exception\ContainerServiceInvalid;
use Wolfcharaa\MessageBus\Exception\ContainerServiceNotFound;
use Wolfcharaa\MessageBus\Invoker\CallableInvokerInterface;
use Wolfcharaa\MessageBus\Registry\HandlerBindingDefinition;
use Wolfcharaa\MessageBus\Registry\HandlerInvocationMode;

final class Pipeline implements PipelineInterface
{
    /** @param list<class-string> $interceptors */
    public function __construct(
        private readonly HandlerBindingDefinition $binding,
        private readonly MessageContextInterface $context,
        private readonly CallableInvokerInterface $invoker,
        private array $interceptors,
    ) {
    }

    public function continue(): mixed
    {
        $interceptor = \array_shift($this->interceptors);

        if ($interceptor !== null) {
            try {
                return $this->invoker->invoke($interceptor, '__invoke', [$this->context, $this]);
            } catch (ContainerServiceNotFound $e) {
                throw $e->withContext('interceptor', $this->binding->bindingId, $this->binding->flow);
            } catch (ContainerServiceInvalid $e) {
                throw $e->withContext('interceptor', $this->binding->bindingId, $this->binding->flow);
            }
        }

        try {
            $arguments = [$this->context->envelope()->message];
            if ($this->binding->invocationMode === HandlerInvocationMode::ContextAware) {
                $arguments[] = $this->context;
            }

            return $this->invoker->invoke(
                $this->binding->action,
                $this->binding->method,
                $arguments,
            );
        } catch (ContainerServiceNotFound $e) {
            throw $e->withContext('handler', $this->binding->bindingId, $this->binding->flow);
        } catch (ContainerServiceInvalid $e) {
            throw $e->withContext('handler', $this->binding->bindingId, $this->binding->flow);
        }
    }
}
