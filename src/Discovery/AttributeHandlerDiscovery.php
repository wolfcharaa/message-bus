<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Discovery;

use ReflectionClass;
use Wolfcharaa\MessageBus\Attribute\CacheResult;
use Wolfcharaa\MessageBus\Attribute\MessageAlias;
use Wolfcharaa\MessageBus\Attribute\MessageHandlerAttributeInterface;
use Wolfcharaa\MessageBus\Registry\HandlerBindingDefinition;
use Wolfcharaa\MessageBus\Registry\RegistryCompilationException;
use Wolfcharaa\MessageBus\Registry\RegistryDiagnostic;
use Wolfcharaa\MessageBus\Registry\RegistryDiagnosticCodes;
use Wolfcharaa\MessageBus\Registry\RegistryDiagnosticOrigin;
use Wolfcharaa\MessageBus\Registry\RegistryDiagnosticOriginKind;
use Wolfcharaa\MessageBus\Registry\RegistryDiagnosticTarget;

final class AttributeHandlerDiscovery
{
    private const SOURCE_RULE = 'message_bus.discovery.attribute_handler';

    /**
     * @return array{bindings: list<HandlerBindingDefinition>, aliases: array<string, class-string>, messageNames: array<class-string, string>}
     */
    public function discover(ClassProviderInterface $provider): array
    {
        // TODO(next-major): make diagnostics result the primary discovery API and keep this method as a thin BC adapter only.
        $result = $this->discoverWithDiagnostics($provider);
        if ($result->hasErrors()) {
            throw RegistryCompilationException::fromDiagnostics($result->diagnostics, 'Attribute handler discovery failed');
        }

        return $result->toArray();
    }

    public function discoverWithDiagnostics(ClassProviderInterface $provider): AttributeDiscoveryResult
    {
        $bindings = [];
        $aliases = [];
        $messageNames = [];
        $diagnostics = [];

        foreach ($provider->classes() as $class) {
            if (!\class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);
            $aliasAttributes = $reflection->getAttributes(MessageAlias::class);

            if (\count($aliasAttributes) > 1) {
                $diagnostics[] = RegistryDiagnostic::error(
                    RegistryDiagnosticCodes::ALIAS_MULTIPLE_FOR_MESSAGE,
                    \sprintf('Message `%s` declares multiple MessageAlias attributes.', $class),
                    $this->origin($reflection, MessageAlias::class),
                    new RegistryDiagnosticTarget(messageClass: $class),
                    'Keep exactly one stable MessageAlias on a message class.',
                );
            } else {
                foreach ($aliasAttributes as $attribute) {
                    /** @var MessageAlias $alias */
                    $alias = $attribute->newInstance();
                    self::registerAlias($alias->name, $class, $aliases, $messageNames, $diagnostics, $this->origin($reflection, MessageAlias::class));
                }
            }

            $classBindings = [];
            foreach ($reflection->getAttributes() as $attribute) {
                $attributeClass = $attribute->getName();
                if (!\is_a($attributeClass, MessageHandlerAttributeInterface::class, true)) {
                    continue;
                }

                /** @var MessageHandlerAttributeInterface $instance */
                $instance = $attribute->newInstance();
                $classBindings[] = $instance->toBinding($class);
            }

            foreach ($this->cachePolicies($reflection, $classBindings, $diagnostics) as $binding) {
                $bindings[] = $binding;
            }
        }

        return new AttributeDiscoveryResult($bindings, $aliases, $messageNames, $diagnostics);
    }

    /**
     * @param list<HandlerBindingDefinition> $bindings
     * @param list<RegistryDiagnostic> $diagnostics
     * @return list<HandlerBindingDefinition>
     */
    private function cachePolicies(ReflectionClass $reflection, array $bindings, array &$diagnostics): array
    {
        $attributes = $reflection->getAttributes(CacheResult::class);
        if ($attributes === []) {
            return $bindings;
        }

        $withCache = $bindings;
        foreach ($attributes as $attribute) {
            /** @var CacheResult $cache */
            $cache = $attribute->newInstance();

            if (\count($bindings) > 1 && $cache->bindingId === null) {
                $diagnostics[] = RegistryDiagnostic::error(
                    RegistryDiagnosticCodes::CACHE_BINDING_ID_REQUIRED,
                    \sprintf(
                        'CacheResult on `%s` must declare bindingId because action has multiple bindings.',
                        $reflection->getName(),
                    ),
                    $this->origin($reflection, CacheResult::class),
                    new RegistryDiagnosticTarget(handlerClass: $reflection->getName()),
                    'Set CacheResult bindingId to the exact handler binding it configures.',
                );

                continue;
            }

            foreach ($withCache as $index => $binding) {
                if ($cache->bindingId === null || $binding->bindingId === $cache->bindingId) {
                    $withCache[$index] = $binding->withCache($cache->toPolicy());
                }
            }
        }

        return $withCache;
    }

    /**
     * @param class-string $message
     * @param array<string, class-string> $aliases
     * @param array<class-string, string> $messageNames
     * @param list<RegistryDiagnostic> $diagnostics
     */
    private static function registerAlias(
        string $alias,
        string $message,
        array &$aliases,
        array &$messageNames,
        array &$diagnostics,
        RegistryDiagnosticOrigin $origin,
    ): void {
        if (isset($aliases[$alias]) && $aliases[$alias] !== $message) {
            $diagnostics[] = RegistryDiagnostic::error(
                RegistryDiagnosticCodes::ALIAS_DUPLICATE,
                \sprintf('Duplicate MessageAlias `%s` for `%s` and `%s`.', $alias, $aliases[$alias], $message),
                $origin,
                new RegistryDiagnosticTarget(messageClass: $message, alias: $alias),
                'Use a unique stable alias for each message class.',
            );

            return;
        }

        if (isset($messageNames[$message]) && $messageNames[$message] !== $alias) {
            $diagnostics[] = RegistryDiagnostic::error(
                RegistryDiagnosticCodes::ALIAS_MULTIPLE_FOR_MESSAGE,
                \sprintf('Message `%s` declares multiple aliases: `%s` and `%s`.', $message, $messageNames[$message], $alias),
                $origin,
                new RegistryDiagnosticTarget(messageClass: $message, alias: $alias),
                'Keep exactly one stable MessageAlias on a message class.',
            );

            return;
        }

        $aliases[$alias] = $message;
        $messageNames[$message] = $alias;
    }

    private function origin(ReflectionClass $reflection, string $attributeClass): RegistryDiagnosticOrigin
    {
        return RegistryDiagnosticOrigin::fromReflectionClass(
            $reflection,
            RegistryDiagnosticOriginKind::Attribute,
            $attributeClass,
            self::SOURCE_RULE,
        );
    }
}
