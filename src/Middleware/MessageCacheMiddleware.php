<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Middleware;

use Psr\SimpleCache\CacheInterface;
use Wolfcharaa\MessageBus\Cache\CacheAllResultsPolicy;
use Wolfcharaa\MessageBus\Cache\CachePolicy;
use Wolfcharaa\MessageBus\Cache\CachePolicyRegistryInterface;
use Wolfcharaa\MessageBus\Cache\CacheResultPolicyInterface;
use Wolfcharaa\MessageBus\Cache\JsonResultSerializer;
use Wolfcharaa\MessageBus\Cache\ResultSerializerInterface;
use Wolfcharaa\MessageBus\Cache\SerializedResult;
use Wolfcharaa\MessageBus\Context\MessageContextInterface;

final class MessageCacheMiddleware
{
    public function __construct(
        private readonly CacheInterface $cache,
        private readonly ?CachePolicyRegistryInterface $policies = null,
        private readonly ResultSerializerInterface $serializer = new JsonResultSerializer(),
        private readonly CacheResultPolicyInterface $resultPolicy = new CacheAllResultsPolicy(),
    ) {
    }

    public function __invoke(MessageContextInterface $context, PipelineInterface $pipeline): mixed
    {
        $policy = $this->policy($context);

        if ($policy === null || !$policy->enabled || $policy->ttlSeconds === null) {
            return $pipeline->continue();
        }

        $key = $this->cacheKey($context, $policy);
        $cached = $this->cache->get($key);

        if (\is_array($cached)) {
            return $this->serializer->deserialize(new SerializedResult(
                $cached['contentType'],
                $cached['payload'],
                $cached['className'] ?? null,
            ));
        }

        $result = $pipeline->continue();
        if (!$this->resultPolicy->shouldCache($context, $policy, $result)) {
            return $result;
        }

        $serialized = $this->serializer->serialize($result);
        $this->cache->set($key, [
            'contentType' => $serialized->contentType,
            'payload' => $serialized->payload,
            'className' => $serialized->className,
        ], $policy->ttlSeconds);

        return $result;
    }

    public function invalidate(MessageContextInterface $context, CachePolicy $policy): void
    {
        $this->cache->delete($this->cacheKey($context, $policy));
    }

    private function policy(MessageContextInterface $context): ?CachePolicy
    {
        $headers = $context->envelope()->headers;
        $ttl = $headers->get('cache.ttl_seconds');
        $identity = $headers->get('cache.identity_key');
        $enabled = $headers->get('cache.enabled');

        if ($ttl !== null || $identity !== null || $enabled !== null) {
            return new CachePolicy(
                $ttl !== null ? (int) $ttl : null,
                $identity !== null ? (string) $identity : null,
                [],
                $enabled === null || (bool) $enabled,
            );
        }

        $bindingId = $context->envelope()->bindingId;

        return $bindingId === null ? null : $this->policies?->forBinding($bindingId);
    }

    private function cacheKey(MessageContextInterface $context, CachePolicy $policy): string
    {
        $message = $context->envelope()->message;
        $vary = [];
        foreach ($policy->varyHeaders as $header) {
            $vary[$header] = $context->envelope()->headers->get($header);
        }
        $this->sortRecursive($vary);

        return \sprintf(
            'message_cache.%s',
            \hash('sha256', \implode('|', [
                $message::class,
                $policy->identityKey ?? '',
                \base64_encode(\serialize($message)),
                \base64_encode(\serialize($vary)),
            ])),
        );
    }

    private function sortRecursive(mixed &$value): void
    {
        if (!\is_array($value)) {
            return;
        }

        \ksort($value);
        foreach ($value as &$item) {
            $this->sortRecursive($item);
        }
    }
}
