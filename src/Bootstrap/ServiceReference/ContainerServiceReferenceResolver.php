<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Bootstrap\ServiceReference;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;

final readonly class ContainerServiceReferenceResolver implements ServiceReferenceResolverInterface
{
    public function __construct(private ContainerInterface $container)
    {
    }

    public function resolve(ServiceReference $reference): ResolvedServiceReference
    {
        try {
            if (!$this->container->has($reference->id)) {
                throw ServiceReferenceResolutionFailed::notFound($reference);
            }

            $service = $this->container->get($reference->id);
        } catch (NotFoundExceptionInterface $e) {
            throw ServiceReferenceResolutionFailed::notFound($reference, $e);
        } catch (ContainerExceptionInterface $e) {
            throw ServiceReferenceResolutionFailed::containerFailure($reference, $e);
        }

        if (!\is_object($service) || !$service instanceof $reference->expectedType) {
            throw ServiceReferenceResolutionFailed::invalidType(
                $reference,
                \is_object($service) ? $service::class : \get_debug_type($service),
            );
        }

        return new ResolvedServiceReference($reference, $service);
    }
}
