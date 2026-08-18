<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Registry;

use DateTimeImmutable;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionUnionType;
use Wolfcharaa\MessageBus\Attribute\MessageAlias;
use Wolfcharaa\MessageBus\Context\MessageContextFactoryInterface;
use Wolfcharaa\MessageBus\Context\MessageContextInterface;
use Wolfcharaa\MessageBus\Discovery\AttributeHandlerDiscovery;
use Wolfcharaa\MessageBus\Discovery\ClassProviderInterface;
use Wolfcharaa\MessageBus\Flow\FlowDefinition;
use Wolfcharaa\MessageBus\Flow\FlowRegistry;
use Wolfcharaa\MessageBus\Middleware\PipelineInterface;

final class MessageRegistryCompiler
{
    public const SCHEMA_VERSION = 4;

    public function __construct(private readonly AttributeHandlerDiscovery $discovery = new AttributeHandlerDiscovery())
    {
    }

    public function compile(
        ClassProviderInterface $provider,
        ?FlowRegistry $flows = null,
        string $libraryVersion = '4.0.0',
        string $sourceHash = '',
    ): MessageRegistryDefinition {
        $flows ??= new FlowRegistry();
        $discovered = $this->discovery->discover($provider);

        /** @var list<HandlerBindingDefinition> $bindings */
        $bindings = $discovered['bindings'];
        /** @var array<string, class-string> $aliases */
        $aliases = $discovered['aliases'];
        /** @var array<class-string, string> $messageNames */
        $messageNames = $discovered['messageNames'];

        [$aliases, $messageNames] = $this->hydrateMessageAliases($bindings, $aliases, $messageNames);
        $bindings = $this->normalizeBindings($bindings, $flows, $messageNames);
        $this->validateAliases($aliases);
        $this->validateBindings($bindings, $flows, $messageNames);

        $messages = [];
        $bindingMap = [];
        foreach ($bindings as $binding) {
            \assert($binding->bindingId !== null);
            $messages[$binding->message][] = $binding->bindingId;
            $bindingMap[$binding->bindingId] = $binding;
        }

        return new MessageRegistryDefinition(
            self::SCHEMA_VERSION,
            $libraryVersion,
            (new DateTimeImmutable())->format(DATE_ATOM),
            $sourceHash !== '' ? $sourceHash : $this->sourceHash($bindings, $flows, $aliases),
            $flows,
            $messages,
            $bindingMap,
            $aliases,
            $messageNames,
        );
    }

    /**
     * @param list<HandlerBindingDefinition> $bindings
     * @param array<string, class-string> $aliases
     * @param array<class-string, string> $messageNames
     * @return array{array<string, class-string>, array<class-string, string>}
     */
    private function hydrateMessageAliases(array $bindings, array $aliases, array $messageNames): array
    {
        $messages = [];
        foreach ($bindings as $binding) {
            $messages[$binding->message] = true;
        }

        foreach (\array_keys($messages) as $message) {
            if (!\class_exists($message)) {
                continue;
            }

            $attributes = (new ReflectionClass($message))->getAttributes(MessageAlias::class);
            if ($attributes === []) {
                continue;
            }

            if (\count($attributes) > 1) {
                throw new RegistryCompilationException(\sprintf('Message `%s` must declare only one MessageAlias.', $message));
            }

            /** @var MessageAlias $alias */
            $alias = $attributes[0]->newInstance();
            $this->registerAlias($alias->name, $message, $aliases, $messageNames);
        }

        return [$aliases, $messageNames];
    }

    /**
     * @param class-string $message
     * @param array<string, class-string> $aliases
     * @param array<class-string, string> $messageNames
     */
    private function registerAlias(string $alias, string $message, array &$aliases, array &$messageNames): void
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

    /**
     * @param list<HandlerBindingDefinition> $bindings
     * @param array<class-string, string> $messageNames
     * @return list<HandlerBindingDefinition>
     */
    private function normalizeBindings(array $bindings, FlowRegistry $flows, array $messageNames): array
    {
        $byMessageKind = [];

        foreach ($bindings as $binding) {
            $flow = $flows->get($binding->flow);
            $bindingId = $binding->bindingId;

            if ($bindingId === null && $flow->isAsync()) {
                throw new RegistryCompilationException(\sprintf(
                    'Async binding for `%s -> %s` must declare stable bindingId.',
                    $binding->message,
                    $binding->action,
                ));
            }

            if ($bindingId === null) {
                $bindingId = $this->autoBindingId($binding);
                $binding = $binding->withBindingId($bindingId);
            }

            if ($flow->isAsync() && !isset($messageNames[$binding->message])) {
                throw new RegistryCompilationException(\sprintf(
                    'Async message `%s` must declare MessageAlias.',
                    $binding->message,
                ));
            }

            $byMessageKind[$binding->message][$binding->kind->value][] = $binding;
        }

        $normalized = [];
        foreach ($byMessageKind as $byKind) {
            foreach ($byKind as $kindBindings) {
                foreach ($this->normalizePrimary($kindBindings, $flows) as $binding) {
                    $normalized[] = $binding;
                }
            }
        }

        return $normalized;
    }

    /**
     * @param non-empty-list<HandlerBindingDefinition> $bindings
     * @return non-empty-list<HandlerBindingDefinition>
     */
    private function normalizePrimary(array $bindings, FlowRegistry $flows): array
    {
        $kind = $bindings[0]->kind;

        if ($kind === HandlerKind::Event) {
            return $bindings;
        }

        if ($kind === HandlerKind::Query) {
            if (\count($bindings) !== 1) {
                throw new RegistryCompilationException(\sprintf(
                    'Query message `%s` must have exactly one handler.',
                    $bindings[0]->message,
                ));
            }

            return [$bindings[0]->withPrimary(true)];
        }

        $syncBindings = \array_values(\array_filter(
            $bindings,
            static fn (HandlerBindingDefinition $binding): bool => $flows->get($binding->flow)->isSync(),
        ));

        if ($syncBindings === []) {
            return $bindings;
        }

        $primary = \array_values(\array_filter(
            $syncBindings,
            static fn (HandlerBindingDefinition $binding): bool => $binding->primary === true,
        ));

        if (\count($syncBindings) === 1 && $syncBindings[0]->primary === null) {
            $autoPrimary = $syncBindings[0]->withPrimary(true);

            return \array_map(
                static fn (HandlerBindingDefinition $binding): HandlerBindingDefinition => $binding === $syncBindings[0] ? $autoPrimary : $binding,
                $bindings,
            );
        }

        if (\count($syncBindings) > 1 && $primary === []) {
            throw new RegistryCompilationException(\sprintf(
                'Command message `%s` has multiple handlers and no primary binding.',
                $syncBindings[0]->message,
            ));
        }

        if (\count($primary) > 1) {
            throw new RegistryCompilationException(\sprintf(
                'Command message `%s` has more than one primary binding.',
                $syncBindings[0]->message,
            ));
        }

        return $bindings;
    }

    /** @param array<string, class-string> $aliases */
    private function validateAliases(array $aliases): void
    {
        foreach ($aliases as $alias => $class) {
            if (!\class_exists($class)) {
                throw new RegistryCompilationException(\sprintf('Alias `%s` points to missing class `%s`.', $alias, $class));
            }
        }
    }

    /**
     * @param list<HandlerBindingDefinition> $bindings
     * @param array<class-string, string> $messageNames
     */
    private function validateBindings(array $bindings, FlowRegistry $flows, array $messageNames): void
    {
        $bindingIds = [];

        foreach ($flows->all() as $flow) {
            $this->validateFlow($flow);
        }

        foreach ($bindings as $binding) {
            if ($binding->bindingId === null) {
                throw new RegistryCompilationException('Binding id was not normalized.');
            }

            if (isset($bindingIds[$binding->bindingId])) {
                throw new RegistryCompilationException(\sprintf('Duplicate bindingId `%s`.', $binding->bindingId));
            }
            $bindingIds[$binding->bindingId] = true;

            $flow = $flows->get($binding->flow);
            $this->validateHandlerSignature($binding, $flow);

            foreach ([...$flow->middleware, ...$binding->middleware] as $middleware) {
                $this->validateMiddlewareSignature($middleware, $flow);
            }

            if ($binding->kind === HandlerKind::Query && !$flow->isSync()) {
                throw new RegistryCompilationException(\sprintf('Query `%s` must be bound to sync flow.', $binding->message));
            }

            if ($flow->isAsync() && !isset($messageNames[$binding->message])) {
                throw new RegistryCompilationException(\sprintf('Async message `%s` must have alias.', $binding->message));
            }
        }
    }

    private function validateFlow(FlowDefinition $flow): void
    {
        if (!\interface_exists($flow->contextInterface) && !\class_exists($flow->contextInterface)) {
            throw new RegistryCompilationException(\sprintf('Flow `%s` context `%s` does not exist.', $flow->key, $flow->contextInterface));
        }

        if (!\is_a($flow->contextInterface, MessageContextInterface::class, true)) {
            throw new RegistryCompilationException(\sprintf(
                'Flow `%s` context `%s` must extend `%s`.',
                $flow->key,
                $flow->contextInterface,
                MessageContextInterface::class,
            ));
        }

        if ($flow->contextFactory === null) {
            throw new RegistryCompilationException(\sprintf('Flow `%s` must declare context factory.', $flow->key));
        }

        if (!\class_exists($flow->contextFactory)) {
            throw new RegistryCompilationException(\sprintf('Flow `%s` context factory `%s` does not exist.', $flow->key, $flow->contextFactory));
        }

        if (!\is_a($flow->contextFactory, MessageContextFactoryInterface::class, true)) {
            throw new RegistryCompilationException(\sprintf(
                'Flow `%s` context factory `%s` must implement `%s`.',
                $flow->key,
                $flow->contextFactory,
                MessageContextFactoryInterface::class,
            ));
        }

        if (!\class_exists($flow->strategy)) {
            throw new RegistryCompilationException(\sprintf('Flow `%s` strategy `%s` does not exist.', $flow->key, $flow->strategy));
        }

        if ($flow->isAsync() && $flow->transport === null) {
            throw new RegistryCompilationException(\sprintf('Async flow `%s` must declare transport.', $flow->key));
        }
    }

    private function validateHandlerSignature(HandlerBindingDefinition $binding, FlowDefinition $flow): void
    {
        if (!\class_exists($binding->message)) {
            throw new RegistryCompilationException(\sprintf('Message class `%s` does not exist.', $binding->message));
        }

        if (!\class_exists($binding->action)) {
            throw new RegistryCompilationException(\sprintf('Action class `%s` does not exist.', $binding->action));
        }

        $method = $this->method($binding->action, $binding->method);
        $params = $method->getParameters();

        if (\count($params) < 2) {
            throw new RegistryCompilationException(\sprintf(
                'Handler `%s::%s` must accept message and context arguments.',
                $binding->action,
                $binding->method,
            ));
        }

        if (!$this->parameterAccepts($params[0]->getType(), $binding->message)) {
            throw new RegistryCompilationException(\sprintf(
                'First argument of `%s::%s` must accept `%s`.',
                $binding->action,
                $binding->method,
                $binding->message,
            ));
        }

        if (!$this->parameterAccepts($params[1]->getType(), $flow->contextInterface)) {
            throw new RegistryCompilationException(\sprintf(
                'Second argument of `%s::%s` must accept flow context `%s`.',
                $binding->action,
                $binding->method,
                $flow->contextInterface,
            ));
        }

        $returnType = $method->getReturnType();
        if ($binding->kind === HandlerKind::Query && $this->isVoid($returnType)) {
            throw new RegistryCompilationException(\sprintf('Query handler `%s::%s` cannot return void.', $binding->action, $binding->method));
        }

        if ($binding->kind === HandlerKind::Event && $returnType !== null && !$this->isVoidOrNull($returnType)) {
            throw new RegistryCompilationException(\sprintf('Event subscriber `%s::%s` must return void or null.', $binding->action, $binding->method));
        }
    }

    private function validateMiddlewareSignature(string $middleware, FlowDefinition $flow): void
    {
        $method = $this->method($middleware, '__invoke');
        $params = $method->getParameters();

        if (\count($params) < 2) {
            throw new RegistryCompilationException(\sprintf('Middleware `%s` must accept context and pipeline.', $middleware));
        }

        if (!$this->parameterAccepts($params[0]->getType(), $flow->contextInterface)) {
            throw new RegistryCompilationException(\sprintf('Middleware `%s` context argument must accept `%s`.', $middleware, $flow->contextInterface));
        }

        if (!$this->parameterAccepts($params[1]->getType(), PipelineInterface::class)) {
            throw new RegistryCompilationException(\sprintf('Middleware `%s` pipeline argument must accept `%s`.', $middleware, PipelineInterface::class));
        }
    }

    private function method(string $class, string $method): ReflectionMethod
    {
        if (!\class_exists($class) || !\method_exists($class, $method)) {
            throw new RegistryCompilationException(\sprintf('Method `%s::%s` does not exist.', $class, $method));
        }

        return new ReflectionMethod($class, $method);
    }

    private function parameterAccepts(?\ReflectionType $type, string $actualClass): bool
    {
        if ($type instanceof ReflectionNamedType) {
            if ($type->isBuiltin()) {
                return false;
            }

            return \is_a($actualClass, $type->getName(), true);
        }

        if ($type instanceof ReflectionUnionType) {
            foreach ($type->getTypes() as $inner) {
                if ($this->parameterAccepts($inner, $actualClass)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function isVoid(?\ReflectionType $type): bool
    {
        return $type instanceof ReflectionNamedType && $type->getName() === 'void';
    }

    private function isVoidOrNull(\ReflectionType $type): bool
    {
        if ($type instanceof ReflectionNamedType) {
            return $type->getName() === 'void' || $type->getName() === 'null';
        }

        return false;
    }

    private function autoBindingId(HandlerBindingDefinition $binding): string
    {
        return 'auto.' . \sha1(\implode('|', [
            $binding->kind->value,
            $binding->message,
            $binding->action,
            $binding->method,
            $binding->flow,
        ]));
    }

    /**
     * @param list<HandlerBindingDefinition> $bindings
     * @param array<string, class-string> $aliases
     */
    private function sourceHash(array $bindings, FlowRegistry $flows, array $aliases): string
    {
        $data = [
            'bindings' => \array_map(static fn (HandlerBindingDefinition $binding): array => $binding->toArray(), $bindings),
            'flows' => $flows->toArray(),
            'aliases' => $aliases,
        ];

        return \sha1(\json_encode($data, JSON_THROW_ON_ERROR));
    }
}
