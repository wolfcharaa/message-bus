<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Tests;

use PHPUnit\Framework\TestCase;
use Wolfcharaa\MessageBus\Attribute\CacheResult;
use Wolfcharaa\MessageBus\Attribute\CommandHandler;
use Wolfcharaa\MessageBus\Attribute\QueryHandler;
use Wolfcharaa\MessageBus\Context\MessageContextInterface;
use Wolfcharaa\MessageBus\Discovery\ClassListProvider;
use Wolfcharaa\MessageBus\Registry\CompiledMessageRegistry;
use Wolfcharaa\MessageBus\Registry\MessageRegistryCompiler;
use Wolfcharaa\MessageBus\Registry\RegistryCompilationException;

final class RegistryCachePolicyV5Test extends TestCase
{
    public function testCompilerStoresCachePolicyFromAttributeAndCompiledArrayPreservesIt(): void
    {
        $registry = $this->registry([
            RegistryCachePolicyQuery::class,
            RegistryCachePolicyQueryHandler::class,
        ]);

        $binding = $registry->binding('cache.query');
        $loaded = CompiledMessageRegistry::fromArray($registry->definition()->toArray());
        $loadedBinding = $loaded->binding('cache.query');

        self::assertNotNull($binding->cache);
        self::assertSame(120, $binding->cache->ttlSeconds);
        self::assertSame('lookup-user', $binding->cache->identityKey);
        self::assertSame(['tenant'], $binding->cache->varyHeaders);
        self::assertNotNull($loadedBinding->cache);
        self::assertSame(120, $loadedBinding->cache->ttlSeconds);
        self::assertSame('lookup-user', $loadedBinding->cache->identityKey);
        self::assertSame(['tenant'], $loadedBinding->cache->varyHeaders);
    }

    public function testCacheResultRequiresBindingIdForMultiBindingAction(): void
    {
        $this->expectException(RegistryCompilationException::class);
        $this->expectExceptionMessage('must declare bindingId');

        $this->registry([
            RegistryCacheMultiMessageA::class,
            RegistryCacheMultiMessageB::class,
            RegistryCacheAmbiguousMultiAction::class,
        ]);
    }

    public function testCacheResultWithBindingIdTargetsOnlyMatchingBinding(): void
    {
        $registry = $this->registry([
            RegistryCacheMultiMessageA::class,
            RegistryCacheMultiMessageB::class,
            RegistryCacheTargetedMultiAction::class,
        ]);

        self::assertNotNull($registry->binding('multi.a')->cache);
        self::assertSame(30, $registry->binding('multi.a')->cache->ttlSeconds);
        self::assertNull($registry->binding('multi.b')->cache);
    }

    /** @param list<class-string> $classes */
    private function registry(array $classes): CompiledMessageRegistry
    {
        return new CompiledMessageRegistry((new MessageRegistryCompiler())->compile(
            new ClassListProvider($classes),
            null,
            '5.0.0',
            'registry-cache-policy-v5-test',
        ));
    }
}

final class RegistryCachePolicyQuery
{
    public function __construct(public readonly int $id)
    {
    }
}

final class RegistryCachePolicyResult
{
    public function __construct(public readonly string $value)
    {
    }
}

#[CacheResult(ttlSeconds: 120, identityKey: 'lookup-user', varyHeaders: ['tenant'])]
#[QueryHandler(message: RegistryCachePolicyQuery::class, bindingId: 'cache.query')]
final class RegistryCachePolicyQueryHandler
{
    public function __invoke(RegistryCachePolicyQuery $message, MessageContextInterface $context): RegistryCachePolicyResult
    {
        return new RegistryCachePolicyResult('query:' . $message->id);
    }
}

final class RegistryCacheMultiMessageA
{
}

final class RegistryCacheMultiMessageB
{
}

#[CacheResult(ttlSeconds: 60)]
#[CommandHandler(message: RegistryCacheMultiMessageA::class, method: 'handleA')]
#[CommandHandler(message: RegistryCacheMultiMessageB::class, method: 'handleB')]
final class RegistryCacheAmbiguousMultiAction
{
    public function handleA(RegistryCacheMultiMessageA $message, MessageContextInterface $context): string
    {
        return 'a';
    }

    public function handleB(RegistryCacheMultiMessageB $message, MessageContextInterface $context): string
    {
        return 'b';
    }
}

#[CacheResult(ttlSeconds: 30, bindingId: 'multi.a')]
#[CommandHandler(message: RegistryCacheMultiMessageA::class, method: 'handleA', bindingId: 'multi.a')]
#[CommandHandler(message: RegistryCacheMultiMessageB::class, method: 'handleB', bindingId: 'multi.b')]
final class RegistryCacheTargetedMultiAction
{
    public function handleA(RegistryCacheMultiMessageA $message, MessageContextInterface $context): string
    {
        return 'a';
    }

    public function handleB(RegistryCacheMultiMessageB $message, MessageContextInterface $context): string
    {
        return 'b';
    }
}
