<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Registry;

use DateTimeImmutable;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionUnionType;
use Wolfcharaa\MessageBus\Attribute\MessageAlias;
use Wolfcharaa\MessageBus\Context\DefaultMessageContext;
use Wolfcharaa\MessageBus\Context\DefaultMessageContextFactory;
use Wolfcharaa\MessageBus\Context\MessageContextFactoryInterface;
use Wolfcharaa\MessageBus\Context\MessageContextInterface;
use Wolfcharaa\MessageBus\Discovery\AttributeHandlerDiscovery;
use Wolfcharaa\MessageBus\Discovery\ClassProviderInterface;
use Wolfcharaa\MessageBus\Flow\FlowDefinition;
use Wolfcharaa\MessageBus\Flow\FlowRegistry;
use Wolfcharaa\MessageBus\Interceptor\PipelineInterface as InterceptorPipelineInterface;

final class MessageRegistryCompiler
{
    public const SCHEMA_VERSION = 6;
    public const LIBRARY_VERSION = '6.1.0';

    private const SOURCE_COMPILER = 'message_bus.registry.compiler';
    private const SOURCE_FLOW_VALIDATION = 'message_bus.registry.flow_validation';
    private const SOURCE_HANDLER_SIGNATURE = 'message_bus.registry.handler_signature';
    private const SOURCE_INTERCEPTOR_SIGNATURE = 'message_bus.registry.interceptor_signature';

    /** @param iterable<RegistryValidationRuleInterface> $validationRules */
    public function __construct(
        private readonly AttributeHandlerDiscovery $discovery = new AttributeHandlerDiscovery(),
        private readonly iterable $validationRules = [],
    ) {
    }

    public function compile(
        ClassProviderInterface $provider,
        ?FlowRegistry $flows = null,
        string $libraryVersion = self::LIBRARY_VERSION,
        string $sourceHash = '',
        ?MessageRegistryCompilerOptions $options = null,
    ): MessageRegistryDefinition {
        $options ??= new MessageRegistryCompilerOptions();
        $result = $this->compileWithDiagnostics($provider, $flows, $libraryVersion, $sourceHash, $options);

        if ($result->isFailure($options)) {
            throw RegistryCompilationException::fromDiagnostics($result->diagnostics);
        }

        if (!$result->hasDefinition()) {
            throw new RegistryCompilationException('Registry compilation failed: definition was not built.');
        }

        return $result->definition;
    }

    public function compileWithDiagnostics(
        ClassProviderInterface $provider,
        ?FlowRegistry $flows = null,
        string $libraryVersion = self::LIBRARY_VERSION,
        string $sourceHash = '',
        ?MessageRegistryCompilerOptions $options = null,
    ): RegistryCompilationResult {
        $options ??= new MessageRegistryCompilerOptions();
        $flows ??= new FlowRegistry();
        $diagnostics = [];

        $discovered = $this->discovery->discoverWithDiagnostics($provider);
        $diagnostics = [...$diagnostics, ...$discovered->diagnostics];
        $bindings = $discovered->bindings;
        $aliases = $discovered->aliases;
        $messageNames = $discovered->messageNames;
        $graphContext = $this->graphContext(RegistryCompilationStage::HandlersDiscovered, $bindings, $aliases, $messageNames, $flows);

        if ($this->hasErrorDiagnostics($diagnostics)) {
            return new RegistryCompilationResult(null, $diagnostics, $graphContext);
        }

        [$aliases, $messageNames] = $this->hydrateMessageAliases($bindings, $aliases, $messageNames, $diagnostics);
        $graphContext = $this->graphContext(RegistryCompilationStage::AliasesHydrated, $bindings, $aliases, $messageNames, $flows);

        if ($this->hasErrorDiagnostics($diagnostics)) {
            return new RegistryCompilationResult(null, $diagnostics, $graphContext);
        }

        $bindings = $this->normalizeBindings($bindings, $flows, $messageNames, $diagnostics);
        $graphContext = $this->graphContext(RegistryCompilationStage::BindingsNormalized, $bindings, $aliases, $messageNames, $flows);

        if ($this->hasErrorDiagnostics($diagnostics)) {
            return new RegistryCompilationResult(null, $diagnostics, $graphContext);
        }

        $this->validateAliases($aliases, $diagnostics);
        $this->validateBindings($bindings, $flows, $messageNames, $diagnostics);
        $graphContext = $this->graphContext(RegistryCompilationStage::CoreValidated, $bindings, $aliases, $messageNames, $flows);

        if ($this->hasErrorDiagnostics($diagnostics)) {
            return new RegistryCompilationResult(null, $diagnostics, $graphContext);
        }

        foreach ($this->validationRules as $rule) {
            if (!$rule instanceof RegistryValidationRuleInterface) {
                throw new RegistryCompilationException(\sprintf(
                    'Registry validation rule `%s` must implement `%s`.',
                    \is_object($rule) ? $rule::class : \get_debug_type($rule),
                    RegistryValidationRuleInterface::class,
                ));
            }

            foreach ($rule->validate($graphContext) as $diagnostic) {
                if (!$diagnostic instanceof RegistryDiagnostic) {
                    throw new RegistryCompilationException(\sprintf(
                        'Registry validation rule `%s` must return only `%s` instances.',
                        $rule::class,
                        RegistryDiagnostic::class,
                    ));
                }

                $diagnostics[] = $diagnostic;
            }
        }

        $graphContext = $this->graphContext(RegistryCompilationStage::ProjectRulesValidated, $bindings, $aliases, $messageNames, $flows);
        if ($this->hasErrorDiagnostics($diagnostics)) {
            return new RegistryCompilationResult(null, $diagnostics, $graphContext);
        }

        $definition = $this->definition($bindings, $flows, $aliases, $messageNames, $libraryVersion, $sourceHash);
        $graphContext = $this->graphContext(RegistryCompilationStage::DefinitionBuilt, $bindings, $aliases, $messageNames, $flows);

        return new RegistryCompilationResult($definition, $diagnostics, $graphContext);
    }

    /**
     * @param list<HandlerBindingDefinition> $bindings
     * @param array<string, class-string> $aliases
     * @param array<class-string, string> $messageNames
     * @param list<RegistryDiagnostic> $diagnostics
     * @return array{array<string, class-string>, array<class-string, string>}
     */
    private function hydrateMessageAliases(array $bindings, array $aliases, array $messageNames, array &$diagnostics): array
    {
        $messages = [];
        foreach ($bindings as $binding) {
            $messages[$binding->message] = true;
        }

        foreach (\array_keys($messages) as $message) {
            if (!\class_exists($message)) {
                continue;
            }

            $reflection = new ReflectionClass($message);
            $attributes = $reflection->getAttributes(MessageAlias::class);
            if ($attributes === []) {
                continue;
            }

            $origin = RegistryDiagnosticOrigin::fromReflectionClass(
                $reflection,
                RegistryDiagnosticOriginKind::Attribute,
                MessageAlias::class,
                self::SOURCE_COMPILER,
            );

            if (\count($attributes) > 1) {
                $diagnostics[] = RegistryDiagnostic::error(
                    RegistryDiagnosticCodes::ALIAS_MULTIPLE_FOR_MESSAGE,
                    \sprintf('Message `%s` must declare only one MessageAlias.', $message),
                    $origin,
                    new RegistryDiagnosticTarget(messageClass: $message),
                    'Keep exactly one stable MessageAlias on a message class.',
                );

                continue;
            }

            /** @var MessageAlias $alias */
            $alias = $attributes[0]->newInstance();
            $this->registerAlias($alias->name, $message, $aliases, $messageNames, $diagnostics, $origin);
        }

        return [$aliases, $messageNames];
    }

    /**
     * @param class-string $message
     * @param array<string, class-string> $aliases
     * @param array<class-string, string> $messageNames
     * @param list<RegistryDiagnostic> $diagnostics
     */
    private function registerAlias(
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

    /**
     * @param list<HandlerBindingDefinition> $bindings
     * @param array<class-string, string> $messageNames
     * @param list<RegistryDiagnostic> $diagnostics
     * @return list<HandlerBindingDefinition>
     */
    private function normalizeBindings(array $bindings, FlowRegistry $flows, array $messageNames, array &$diagnostics): array
    {
        $byMessageKind = [];

        foreach ($bindings as $binding) {
            $flow = $this->flow($flows, $binding->flow, $diagnostics, $binding);
            if ($flow === null) {
                continue;
            }

            $bindingId = $binding->bindingId;
            if ($bindingId === null && $flow->isAsync()) {
                $diagnostics[] = RegistryDiagnostic::error(
                    RegistryDiagnosticCodes::BINDING_MISSING_ID,
                    \sprintf(
                        'Async binding for `%s -> %s` must declare stable bindingId.',
                        $binding->message,
                        $binding->action,
                    ),
                    $this->originForBinding($binding, self::SOURCE_COMPILER),
                    RegistryDiagnosticTarget::fromBinding($binding),
                    'Set bindingId on async CommandHandler/EventSubscriber attributes.',
                );

                continue;
            }

            if ($bindingId === null) {
                $bindingId = $this->autoBindingId($binding);
                $binding = $binding->withBindingId($bindingId);
            }

            if ($flow->isAsync() && !isset($messageNames[$binding->message])) {
                $diagnostics[] = RegistryDiagnostic::error(
                    RegistryDiagnosticCodes::MESSAGE_ALIAS_REQUIRED,
                    \sprintf('Async message `%s` must declare MessageAlias.', $binding->message),
                    $this->originForBinding($binding, self::SOURCE_COMPILER),
                    RegistryDiagnosticTarget::fromBinding($binding),
                    'Add MessageAlias to every message that can be serialized into async queue payloads.',
                );

                continue;
            }

            $byMessageKind[$binding->message][$binding->kind->value][] = $binding;
        }

        $normalized = [];
        foreach ($byMessageKind as $byKind) {
            foreach ($byKind as $kindBindings) {
                foreach ($this->normalizePrimary($kindBindings, $flows, $diagnostics) as $binding) {
                    $normalized[] = $binding;
                }
            }
        }

        $this->validateMessageKindConflicts($normalized, $flows, $diagnostics);

        return $normalized;
    }

    /**
     * @param list<HandlerBindingDefinition> $bindings
     * @param list<RegistryDiagnostic> $diagnostics
     */
    private function validateMessageKindConflicts(array $bindings, FlowRegistry $flows, array &$diagnostics): void
    {
        $byMessage = [];

        foreach ($bindings as $binding) {
            $byMessage[$binding->message][] = $binding;
        }

        foreach ($byMessage as $message => $messageBindings) {
            $syncQuery = null;
            $primaryCommand = null;

            foreach ($messageBindings as $binding) {
                $flow = $this->flow($flows, $binding->flow, $diagnostics, $binding);
                if ($flow === null || !$flow->isSync()) {
                    continue;
                }

                if ($binding->kind === HandlerKind::Query) {
                    $syncQuery = $binding;
                }

                if ($binding->kind === HandlerKind::Command && $binding->primary === true) {
                    $primaryCommand = $binding;
                }
            }

            if ($syncQuery === null || $primaryCommand === null) {
                continue;
            }

            $diagnostics[] = RegistryDiagnostic::error(
                RegistryDiagnosticCodes::MESSAGE_KIND_CONFLICT,
                \sprintf('Message `%s` cannot have both sync QueryHandler and primary sync CommandHandler.', $message),
                $this->originForBinding($primaryCommand, self::SOURCE_COMPILER),
                RegistryDiagnosticTarget::fromBinding($primaryCommand),
                'Split read and write intentions into separate messages or keep only one dispatch role.',
            );
        }
    }

    /**
     * @param non-empty-list<HandlerBindingDefinition> $bindings
     * @param list<RegistryDiagnostic> $diagnostics
     * @return list<HandlerBindingDefinition>
     */
    private function normalizePrimary(array $bindings, FlowRegistry $flows, array &$diagnostics): array
    {
        $kind = $bindings[0]->kind;

        if ($kind === HandlerKind::Event) {
            return $bindings;
        }

        if ($kind === HandlerKind::Query) {
            if (\count($bindings) !== 1) {
                $diagnostics[] = RegistryDiagnostic::error(
                    RegistryDiagnosticCodes::QUERY_HANDLER_COUNT,
                    \sprintf('Query message `%s` must have exactly one handler.', $bindings[0]->message),
                    $this->originForBinding($bindings[0], self::SOURCE_COMPILER),
                    RegistryDiagnosticTarget::fromBinding($bindings[0]),
                    'Keep queries single-handler or convert the fan-out behavior into events.',
                );

                return [];
            }

            return [$bindings[0]->withPrimary(true)];
        }

        $syncBindings = \array_values(\array_filter(
            $bindings,
            fn (HandlerBindingDefinition $binding): bool => $this->flow($flows, $binding->flow, $diagnostics, $binding)?->isSync() ?? false,
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
            $diagnostics[] = RegistryDiagnostic::error(
                RegistryDiagnosticCodes::COMMAND_PRIMARY_MISSING,
                \sprintf('Command message `%s` has multiple handlers and no primary binding.', $syncBindings[0]->message),
                $this->originForBinding($syncBindings[0], self::SOURCE_COMPILER),
                RegistryDiagnosticTarget::fromBinding($syncBindings[0]),
                'Mark exactly one sync command binding as primary.',
            );

            return [];
        }

        if (\count($primary) > 1) {
            $diagnostics[] = RegistryDiagnostic::error(
                RegistryDiagnosticCodes::COMMAND_PRIMARY_DUPLICATE,
                \sprintf('Command message `%s` has more than one primary binding.', $syncBindings[0]->message),
                $this->originForBinding($syncBindings[0], self::SOURCE_COMPILER),
                RegistryDiagnosticTarget::fromBinding($syncBindings[0]),
                'Keep only one primary sync command binding.',
            );

            return [];
        }

        return $bindings;
    }

    /**
     * @param array<string, class-string> $aliases
     * @param list<RegistryDiagnostic> $diagnostics
     */
    private function validateAliases(array $aliases, array &$diagnostics): void
    {
        foreach ($aliases as $alias => $class) {
            if (!\class_exists($class)) {
                $diagnostics[] = RegistryDiagnostic::error(
                    RegistryDiagnosticCodes::ALIAS_MISSING_CLASS,
                    \sprintf('Alias `%s` points to missing class `%s`.', $alias, $class),
                    RegistryDiagnosticOrigin::compiler($alias, self::SOURCE_COMPILER),
                    new RegistryDiagnosticTarget(messageClass: $class, alias: $alias),
                    'Remove the stale alias or restore the referenced message class.',
                );
            }
        }
    }

    /**
     * @param list<HandlerBindingDefinition> $bindings
     * @param array<class-string, string> $messageNames
     * @param list<RegistryDiagnostic> $diagnostics
     */
    private function validateBindings(
        array $bindings,
        FlowRegistry $flows,
        array $messageNames,
        array &$diagnostics,
    ): void
    {
        $bindingIds = [];

        foreach ($flows->all() as $flow) {
            $this->validateFlow($flow, $diagnostics);
        }

        foreach ($bindings as $binding) {
            if ($binding->bindingId === null) {
                $diagnostics[] = RegistryDiagnostic::error(
                    RegistryDiagnosticCodes::BINDING_MISSING_ID,
                    'Binding id was not normalized.',
                    $this->originForBinding($binding, self::SOURCE_COMPILER),
                    RegistryDiagnosticTarget::fromBinding($binding),
                    'Report this as a compiler bug if the binding is not async.',
                );

                continue;
            }

            if (isset($bindingIds[$binding->bindingId])) {
                $diagnostics[] = RegistryDiagnostic::error(
                    RegistryDiagnosticCodes::BINDING_DUPLICATE_ID,
                    \sprintf('Duplicate bindingId `%s`.', $binding->bindingId),
                    $this->originForBinding($binding, self::SOURCE_COMPILER),
                    RegistryDiagnosticTarget::fromBinding($binding),
                    'Use a unique stable bindingId for every handler binding.',
                );
            }
            $bindingIds[$binding->bindingId] = true;

            $flow = $this->flow($flows, $binding->flow, $diagnostics, $binding);
            if ($flow === null) {
                continue;
            }

            $this->validateHandlerSignature($binding, $flow, $diagnostics);

            foreach ([...$flow->middleware, ...$binding->middleware] as $middleware) {
                $this->validateInterceptorSignature($middleware, $flow, $diagnostics, $binding);
            }

            if ($binding->kind === HandlerKind::Query && !$flow->isSync()) {
                $diagnostics[] = RegistryDiagnostic::error(
                    RegistryDiagnosticCodes::QUERY_ASYNC_FLOW,
                    \sprintf('Query `%s` must be bound to sync flow.', $binding->message),
                    $this->originForBinding($binding, self::SOURCE_COMPILER),
                    RegistryDiagnosticTarget::fromBinding($binding),
                    'Queries must return a result immediately; use command/event for async work.',
                );
            }

            if ($flow->isAsync() && !isset($messageNames[$binding->message])) {
                $diagnostics[] = RegistryDiagnostic::error(
                    RegistryDiagnosticCodes::MESSAGE_ALIAS_REQUIRED,
                    \sprintf('Async message `%s` must have alias.', $binding->message),
                    $this->originForBinding($binding, self::SOURCE_COMPILER),
                    RegistryDiagnosticTarget::fromBinding($binding),
                    'Add MessageAlias to every message that can be serialized into async queue payloads.',
                );
            }
        }
    }

    /** @param list<RegistryDiagnostic> $diagnostics */
    private function validateFlow(FlowDefinition $flow, array &$diagnostics): void
    {
        $origin = RegistryDiagnosticOrigin::flow($flow->key, self::SOURCE_FLOW_VALIDATION);
        $target = new RegistryDiagnosticTarget(flow: $flow->key);

        if (!\interface_exists($flow->contextInterface) && !\class_exists($flow->contextInterface)) {
            $diagnostics[] = RegistryDiagnostic::error(
                RegistryDiagnosticCodes::FLOW_INVALID,
                \sprintf('Flow `%s` context `%s` does not exist.', $flow->key, $flow->contextInterface),
                $origin,
                $target,
                'Register an existing MessageContextInterface implementation for the flow context.',
            );

            return;
        }

        if (!\is_a($flow->contextInterface, MessageContextInterface::class, true)) {
            $diagnostics[] = RegistryDiagnostic::error(
                RegistryDiagnosticCodes::FLOW_INVALID,
                \sprintf('Flow `%s` context `%s` must extend `%s`.', $flow->key, $flow->contextInterface, MessageContextInterface::class),
                $origin,
                $target,
                'Flow context must implement MessageContextInterface.',
            );
        }

        if ($flow->contextFactory === null) {
            $diagnostics[] = RegistryDiagnostic::error(
                RegistryDiagnosticCodes::FLOW_INVALID,
                \sprintf('Flow `%s` must declare context factory.', $flow->key),
                $origin,
                $target,
                'Set context factory explicitly when the default context factory cannot be inferred.',
            );

            return;
        }

        if (!\class_exists($flow->contextFactory)) {
            $diagnostics[] = RegistryDiagnostic::error(
                RegistryDiagnosticCodes::FLOW_INVALID,
                \sprintf('Flow `%s` context factory `%s` does not exist.', $flow->key, $flow->contextFactory),
                $origin,
                $target,
                'Register an existing MessageContextFactoryInterface implementation.',
            );

            return;
        }

        if (!\is_a($flow->contextFactory, MessageContextFactoryInterface::class, true)) {
            $diagnostics[] = RegistryDiagnostic::error(
                RegistryDiagnosticCodes::FLOW_INVALID,
                \sprintf('Flow `%s` context factory `%s` must implement `%s`.', $flow->key, $flow->contextFactory, MessageContextFactoryInterface::class),
                $origin,
                $target,
                'Flow context factory must implement MessageContextFactoryInterface.',
            );
        }

        if (!\class_exists($flow->strategy)) {
            $diagnostics[] = RegistryDiagnostic::error(
                RegistryDiagnosticCodes::FLOW_INVALID,
                \sprintf('Flow `%s` strategy `%s` does not exist.', $flow->key, $flow->strategy),
                $origin,
                $target,
                'Register an existing execution strategy class.',
            );
        }

        if ($flow->isAsync() && $flow->transport === null) {
            $diagnostics[] = RegistryDiagnostic::error(
                RegistryDiagnosticCodes::FLOW_INVALID,
                \sprintf('Async flow `%s` must declare transport.', $flow->key),
                $origin,
                $target,
                'Async flows must declare transport and queue.',
            );
        }
    }

    /** @param list<RegistryDiagnostic> $diagnostics */
    private function validateHandlerSignature(HandlerBindingDefinition $binding, FlowDefinition $flow, array &$diagnostics): void
    {
        $target = RegistryDiagnosticTarget::fromBinding($binding);
        $origin = $this->originForBinding($binding, self::SOURCE_HANDLER_SIGNATURE);

        if (!\class_exists($binding->message)) {
            $diagnostics[] = RegistryDiagnostic::error(
                RegistryDiagnosticCodes::HANDLER_INVALID_SIGNATURE,
                \sprintf('Message class `%s` does not exist.', $binding->message),
                $origin,
                $target,
                'Use an existing message class in the handler attribute.',
            );

            return;
        }

        if (!\class_exists($binding->action)) {
            $diagnostics[] = RegistryDiagnostic::error(
                RegistryDiagnosticCodes::HANDLER_INVALID_SIGNATURE,
                \sprintf('Action class `%s` does not exist.', $binding->action),
                $origin,
                $target,
                'Use an existing handler class in the binding.',
            );

            return;
        }

        if (!\method_exists($binding->action, $binding->method)) {
            $diagnostics[] = RegistryDiagnostic::error(
                RegistryDiagnosticCodes::HANDLER_INVALID_SIGNATURE,
                \sprintf('Method `%s::%s` does not exist.', $binding->action, $binding->method),
                $origin,
                $target,
                'Set the handler attribute method to an existing callable method.',
            );

            return;
        }

        $method = new ReflectionMethod($binding->action, $binding->method);
        $origin = RegistryDiagnosticOrigin::fromReflectionMethod($method, self::SOURCE_HANDLER_SIGNATURE);
        $params = $method->getParameters();
        $contextless = $binding->invocationMode === HandlerInvocationMode::Contextless;

        if ($params === []) {
            $diagnostics[] = RegistryDiagnostic::error(
                RegistryDiagnosticCodes::HANDLER_INVALID_SIGNATURE,
                \sprintf('Handler `%s::%s` must accept message argument.', $binding->action, $binding->method),
                $origin,
                $target,
                'Handler signature must start with the message class from the handler attribute.',
            );

            return;
        }

        if (!$this->parameterAccepts($params[0]->getType(), $binding->message)) {
            $diagnostics[] = RegistryDiagnostic::error(
                RegistryDiagnosticCodes::HANDLER_INVALID_SIGNATURE,
                \sprintf('First argument of `%s::%s` must accept `%s`.', $binding->action, $binding->method, $binding->message),
                $origin,
                $target,
                'Make the first handler argument match the message class from the handler attribute.',
            );
        }

        if ($contextless) {
            if (\count($params) > 1) {
                $diagnostics[] = RegistryDiagnostic::error(
                    RegistryDiagnosticCodes::HANDLER_INVALID_SIGNATURE,
                    \sprintf('Contextless handler `%s::%s` must not accept MessageContextInterface.', $binding->action, $binding->method),
                    $origin,
                    $target,
                    'Set contextAware: true or remove the context argument from the handler method.',
                );
            }

            $this->validateHandlerReturnType($binding, $method, $origin, $target, $diagnostics);

            return;
        }

        if (\count($params) < 2) {
            $diagnostics[] = RegistryDiagnostic::error(
                RegistryDiagnosticCodes::HANDLER_INVALID_SIGNATURE,
                \sprintf('Handler `%s::%s` must accept message and context arguments.', $binding->action, $binding->method),
                $origin,
                $target,
                'Handler signature must be __invoke(Message $message, MessageContextInterface $context): Result.',
            );

            return;
        }

        if (!$this->contextParameterAccepts($params[1]->getType(), $flow)) {
            $diagnostics[] = RegistryDiagnostic::error(
                RegistryDiagnosticCodes::HANDLER_INVALID_SIGNATURE,
                \sprintf('Second argument of `%s::%s` must accept flow context `%s`.', $binding->action, $binding->method, $flow->contextInterface),
                $origin,
                $target,
                'Make the second handler argument compatible with the flow context.',
            );
        }

        $this->validateHandlerReturnType($binding, $method, $origin, $target, $diagnostics);
    }

    /** @param list<RegistryDiagnostic> $diagnostics */
    private function validateHandlerReturnType(
        HandlerBindingDefinition $binding,
        ReflectionMethod $method,
        RegistryDiagnosticOrigin $origin,
        RegistryDiagnosticTarget $target,
        array &$diagnostics,
    ): void {
        $returnType = $method->getReturnType();
        if ($binding->kind === HandlerKind::Query && ($returnType === null || $this->isVoid($returnType))) {
            $diagnostics[] = RegistryDiagnostic::error(
                RegistryDiagnosticCodes::HANDLER_INVALID_SIGNATURE,
                \sprintf('Query handler `%s::%s` must declare a non-void return type.', $binding->action, $binding->method),
                $origin,
                $target,
                'Query handlers must return a result value.',
            );
        }

        if ($binding->kind === HandlerKind::Command && !$this->isVoid($returnType)) {
            $diagnostics[] = RegistryDiagnostic::error(
                RegistryDiagnosticCodes::HANDLER_INVALID_SIGNATURE,
                \sprintf('Command handler `%s::%s` must return void.', $binding->action, $binding->method),
                $origin,
                $target,
                'Commands express processing rules only. Use a QueryHandler when application code needs a result.',
            );
        }

        if ($binding->kind === HandlerKind::Event && $returnType !== null && !$this->isVoidOrNull($returnType)) {
            $diagnostics[] = RegistryDiagnostic::error(
                RegistryDiagnosticCodes::HANDLER_INVALID_SIGNATURE,
                \sprintf('Event subscriber `%s::%s` must return void or null.', $binding->action, $binding->method),
                $origin,
                $target,
                'Event subscribers should not return business results.',
            );
        }
    }

    /** @param list<RegistryDiagnostic> $diagnostics */
    private function validateInterceptorSignature(
        string $middleware,
        FlowDefinition $flow,
        array &$diagnostics,
        HandlerBindingDefinition $binding,
    ): void
    {
        $target = new RegistryDiagnosticTarget(
            bindingId: $binding->bindingId,
            messageClass: $binding->message,
            handlerClass: $binding->action,
            method: $binding->method,
            flow: $flow->key,
            middlewareClass: $middleware,
        );
        $origin = $this->originForClass($middleware, self::SOURCE_INTERCEPTOR_SIGNATURE);

        if (!\class_exists($middleware) || !\method_exists($middleware, '__invoke')) {
            $diagnostics[] = RegistryDiagnostic::error(
                RegistryDiagnosticCodes::INTERCEPTOR_INVALID_SIGNATURE,
                \sprintf('Method `%s::__invoke` does not exist.', $middleware),
                $origin,
                $target,
                'Interceptor must be invokable.',
            );

            return;
        }

        $method = new ReflectionMethod($middleware, '__invoke');
        $origin = RegistryDiagnosticOrigin::fromReflectionMethod($method, self::SOURCE_INTERCEPTOR_SIGNATURE);
        $params = $method->getParameters();

        if (\count($params) < 2) {
            $diagnostics[] = RegistryDiagnostic::error(
                RegistryDiagnosticCodes::INTERCEPTOR_INVALID_SIGNATURE,
                \sprintf('Interceptor `%s` must accept context and pipeline.', $middleware),
                $origin,
                $target,
                'Interceptor signature must be __invoke(MessageContextInterface $context, PipelineInterface $pipeline): mixed.',
            );

            return;
        }

        if (!$this->contextParameterAccepts($params[0]->getType(), $flow)) {
            $diagnostics[] = RegistryDiagnostic::error(
                RegistryDiagnosticCodes::INTERCEPTOR_INVALID_SIGNATURE,
                \sprintf('Interceptor `%s` context argument must accept `%s`.', $middleware, $flow->contextInterface),
                $origin,
                $target,
                'Make the first interceptor argument compatible with the flow context.',
            );
        }

        if (!$this->parameterAccepts($params[1]->getType(), InterceptorPipelineInterface::class)) {
            $diagnostics[] = RegistryDiagnostic::error(
                RegistryDiagnosticCodes::INTERCEPTOR_INVALID_SIGNATURE,
                \sprintf('Interceptor `%s` pipeline argument must accept `%s`.', $middleware, InterceptorPipelineInterface::class),
                $origin,
                $target,
                'Make the second interceptor argument accept Interceptor\\PipelineInterface.',
            );
        }
    }

    /** @param list<RegistryDiagnostic> $diagnostics */
    private function flow(FlowRegistry $flows, string $key, array &$diagnostics, ?HandlerBindingDefinition $binding = null): ?FlowDefinition
    {
        $registered = $flows->all();
        if (isset($registered[$key])) {
            return $registered[$key];
        }

        $diagnostics[] = RegistryDiagnostic::error(
            RegistryDiagnosticCodes::FLOW_MISSING,
            \sprintf('Message flow `%s` is not registered.', $key),
            RegistryDiagnosticOrigin::flow($key, self::SOURCE_FLOW_VALIDATION),
            $binding !== null ? RegistryDiagnosticTarget::fromBinding($binding) : new RegistryDiagnosticTarget(flow: $key),
            'Register the flow in FlowRegistry or update the handler attribute flow.',
        );

        return null;
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

    private function contextParameterAccepts(?\ReflectionType $type, FlowDefinition $flow): bool
    {
        if ($this->parameterAccepts($type, $flow->contextInterface)) {
            return true;
        }

        return $flow->contextFactory === DefaultMessageContextFactory::class
            && $this->parameterAccepts($type, DefaultMessageContext::class);
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
     * @param array<class-string, string> $messageNames
     */
    private function graphContext(
        RegistryCompilationStage $stage,
        array $bindings,
        array $aliases,
        array $messageNames,
        FlowRegistry $flows,
    ): RegistryCompilationGraphContext {
        return new RegistryCompilationGraphContext($stage, $bindings, $aliases, $messageNames, $flows);
    }

    /**
     * @param list<HandlerBindingDefinition> $bindings
     * @param array<string, class-string> $aliases
     * @param array<class-string, string> $messageNames
     */
    private function definition(
        array $bindings,
        FlowRegistry $flows,
        array $aliases,
        array $messageNames,
        string $libraryVersion,
        string $sourceHash,
    ): MessageRegistryDefinition {
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

    /** @param list<RegistryDiagnostic> $diagnostics */
    private function hasErrorDiagnostics(array $diagnostics): bool
    {
        foreach ($diagnostics as $diagnostic) {
            if ($diagnostic->isError()) {
                return true;
            }
        }

        return false;
    }

    private function originForBinding(HandlerBindingDefinition $binding, string $sourceRule): RegistryDiagnosticOrigin
    {
        return $this->originForClass($binding->action, $sourceRule);
    }

    private function originForClass(string $class, string $sourceRule): RegistryDiagnosticOrigin
    {
        if (\class_exists($class)) {
            return RegistryDiagnosticOrigin::fromReflectionClass(new ReflectionClass($class), sourceRule: $sourceRule);
        }

        return RegistryDiagnosticOrigin::compiler($class, $sourceRule);
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
