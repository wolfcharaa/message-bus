<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Discovery;

use ReflectionClass;
use Wolfcharaa\MessageBus\Attribute\MessageAlias;
use Wolfcharaa\MessageBus\Attribute\MessageHandlerAttributeInterface;
use Wolfcharaa\MessageBus\Registry\HandlerBindingDefinition;
use Wolfcharaa\MessageBus\Registry\RegistryCompilationException;

final class AttributeHandlerDiscovery
{
    /**
     * @return array{bindings: list<HandlerBindingDefinition>, aliases: array<string, class-string>, messageNames: array<class-string, string>}
     */
    public function discover(ClassProviderInterface $provider): array
    {
        $bindings = [];
        $aliases = [];
        $messageNames = [];

        foreach ($provider->classes() as $class) {
            if (!\class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);

            foreach ($reflection->getAttributes(MessageAlias::class) as $attribute) {
                /** @var MessageAlias $alias */
                $alias = $attribute->newInstance();
                self::registerAlias($alias->name, $class, $aliases, $messageNames);
            }

            foreach ($reflection->getAttributes() as $attribute) {
                $instance = $attribute->newInstance();

                if (!$instance instanceof MessageHandlerAttributeInterface) {
                    continue;
                }

                $bindings[] = $instance->toBinding($class);
            }
        }

        return [
            'bindings' => $bindings,
            'aliases' => $aliases,
            'messageNames' => $messageNames,
        ];
    }

    /**
     * @param class-string $message
     * @param array<string, class-string> $aliases
     * @param array<class-string, string> $messageNames
     */
    private static function registerAlias(string $alias, string $message, array &$aliases, array &$messageNames): void
    {
        if (isset($aliases[$alias]) && $aliases[$alias] !== $message) {
            throw new RegistryCompilationException(\sprintf(
                'Duplicate MessageAlias `%s` for `%s` and `%s`.',
                $alias,
                $aliases[$alias],
                $message,
            ));
        }

        if (isset($messageNames[$message]) && $messageNames[$message] !== $alias) {
            throw new RegistryCompilationException(\sprintf(
                'Message `%s` declares multiple aliases: `%s` and `%s`.',
                $message,
                $messageNames[$message],
                $alias,
            ));
        }

        $aliases[$alias] = $message;
        $messageNames[$message] = $alias;
    }
}
