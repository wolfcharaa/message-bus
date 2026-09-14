<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Bootstrap\ServiceReference;

/**
 * @template T of object
 */
interface ServiceReferenceResolverInterface
{
    /**
     * @template TService of object
     * @param ServiceReference<TService> $reference
     * @return ResolvedServiceReference<TService>
     */
    public function resolve(ServiceReference $reference): ResolvedServiceReference;
}
