<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Registry;

use Wolfcharaa\MessageBus\Attribute\Handler;
use Wolfcharaa\MessageBus\Attribute\MessageName;
use Wolfcharaa\MessageBus\Context\MessageContext;
use Wolfcharaa\MessageBus\Context\MessageContextFactories;
use Wolfcharaa\MessageBus\Context\MessageContextFactoryResolver;
use Wolfcharaa\MessageBus\Contract\Command;
use Wolfcharaa\MessageBus\Contract\Event;
use Wolfcharaa\MessageBus\Contract\Message;
use Wolfcharaa\MessageBus\Contract\Query;
use Wolfcharaa\MessageBus\Contract\Request;
use Wolfcharaa\MessageBus\Exception\InvalidMessageBusConfiguration;

/**
 *  Message role derived exclusively from the message class marker.
 *
 * @api tooling
 */
enum MessageRole: string
{
    case Query = 'query';
    case Request = 'request';
    case Command = 'command';
    case Event = 'event';
}

/**
 *  One validated method-level handler binding.
 *
 * @api tooling
 */
final readonly class HandlerBinding
{
    /**
     * @param class-string<Message<mixed>> $messageClass
     * @param class-string $handlerClass
     * @param class-string<MessageContext>|null $contextClass
     */
    public function __construct(
        public string $id,
        public string $messageClass,
        public MessageRole $role,
        public string $handlerClass,
        public string $method,
        public ?string $contextClass,
        public ?string $subscriptionId,
    ) {}
}

/**
 *  Immutable compiled registry consumed by dispatch and worker runtimes.
 *
 * @api tooling
 */
final readonly class CompiledRegistry
{
    /** @param array<class-string<Message<mixed>>, list<HandlerBinding>> $bindings */
    public function __construct(
        private array $bindings,
        public string $fingerprint,
    ) {}

    /**
     * @param Message<mixed> $message
     * @return list<HandlerBinding>
     */
    public function handlersFor(Message $message): array
    {
        return $this->bindings[$message::class] ?? [];
    }

    /** @return array<class-string<Message<mixed>>, list<HandlerBinding>> */
    public function bindings(): array
    {
        return $this->bindings;
    }
}

/**
 *  Bounded source of candidate handler classes for registry compilation.
 *
 * @api spi
 */
interface RegistryClassSource
{
    /** @return iterable<class-string> */
    public function classes(): iterable;
}

/**
 *  Explicit deterministic class source suitable for production composition.
 *
 * @api tooling
 */
final readonly class ExplicitClassSource implements RegistryClassSource
{
    /** @param list<class-string> $classes */
    public function __construct(private array $classes) {}

    public function classes(): iterable
    {
        yield from $this->classes;
    }
}

/**
 *  Bounded Composer classmap source filtered by explicit namespace prefixes.
 *
 * @api tooling
 */
final readonly class ComposerClassMapSource implements RegistryClassSource
{
    /**
     * Only classes already present in the supplied Composer classmaps can be
     * discovered. A class outside its configured PSR-4/classmap path is
     * invisible here; validate Composer autoloading separately with
     * `composer dump-autoload --optimize --strict-psr`.
     *
     * @param list<string> $classMapFiles
     * @param non-empty-list<string> $namespacePrefixes
     */
    public function __construct(
        private array $classMapFiles,
        private array $namespacePrefixes,
    ) {
        if ($this->classMapFiles === [] || $this->namespacePrefixes === []) {
            throw new \InvalidArgumentException('Composer classmap source requires files and namespace prefixes.');
        }
    }

    public function classes(): iterable
    {
        $classes = [];
        foreach ($this->classMapFiles as $file) {
            $map = require $file;
            if (!\is_array($map)) {
                throw new \RuntimeException('Composer classmap file did not return an array: ' . $file);
            }
            foreach (array_keys($map) as $class) {
                if (!\is_string($class)) {
                    continue;
                }
                $allowed = false;
                foreach ($this->namespacePrefixes as $prefix) {
                    if (str_starts_with($class, $prefix)) {
                        $allowed = true;
                        break;
                    }
                }
                if (!$allowed || (!class_exists($class) && !interface_exists($class))) {
                    continue;
                }
                $classes[$class] = true;
            }
        }
        $result = array_keys($classes);
        sort($result, SORT_STRING);

        yield from $result;
    }
}

/**
 *  Additive validation rule run against the complete deterministic registry.
 *
 * @api spi
 */
interface RegistryValidationRule
{
    /** @return list<RegistryDiagnostic> */
    public function validate(CompiledRegistry $registry): array;
}

/**
 *  Severity of a stable compiler diagnostic.
 *
 * @api tooling
 */
enum DiagnosticSeverity: string
{
    case Error = 'error';
    case Warning = 'warning';
}

/**
 *  Machine-readable registry compiler diagnostic.
 *
 * `MBRnnn` and `MBCnnn` are reserved for library rules. Those diagnostics
 * require non-empty symbol, expected and actual fields, and repeat all three
 * values plus the code in `message`. Custom validation rules should use their
 * own stable code prefix.
 *
 * @api tooling
 */
final readonly class RegistryDiagnostic
{
    public function __construct(
        public string $code,
        public DiagnosticSeverity $severity,
        public string $message,
        public ?string $symbol = null,
        public ?string $rule = null,
        public ?string $subjectKind = null,
        public ?string $expected = null,
        public ?string $actual = null,
        public ?string $causeClass = null,
    ) {
        if (preg_match('/^[A-Z][A-Z0-9._-]{2,31}$/D', $this->code) !== 1) {
            throw new \InvalidArgumentException(
                'Registry diagnostic code must match [A-Z][A-Z0-9._-]{2,31}.',
            );
        }
        if ($this->message === '') {
            throw new \InvalidArgumentException('Registry diagnostic message must not be empty.');
        }
        if (preg_match('/^MB[RC][0-9]{3}$/D', $this->code) === 1) {
            foreach (['symbol' => $this->symbol, 'expected' => $this->expected, 'actual' => $this->actual] as $field => $value) {
                if ($value === null || $value === '') {
                    throw new \InvalidArgumentException(
                        'Built-in registry diagnostic ' . $this->code . ' requires non-empty ' . $field . '.',
                    );
                }
                if (!str_contains($this->message, $value)) {
                    throw new \InvalidArgumentException(
                        'Built-in registry diagnostic ' . $this->code . ' message must include ' . $field . '.',
                    );
                }
            }
            if (!str_contains($this->message, '[' . $this->code . ']')) {
                throw new \InvalidArgumentException(
                    'Built-in registry diagnostic message must include [' . $this->code . '].',
                );
            }
        }
    }

    /** @return array<string, string|null> */
    public function normalized(): array
    {
        return [
            'code' => $this->code,
            'severity' => $this->severity->value,
            'message' => $this->message,
            'symbol' => $this->symbol,
            'rule' => $this->rule ?? $this->code,
            'subject_kind' => $this->subjectKind ?? 'symbol',
            'expected' => $this->expected,
            'actual' => $this->actual,
            'cause_class' => $this->causeClass,
        ];
    }
}

/**
 *  Registry compilation outcome with a stable diagnostic catalog.
 *
 * @api tooling
 */
final readonly class RegistryCompilationResult
{
    /** @param list<RegistryDiagnostic> $diagnostics */
    public function __construct(
        public ?CompiledRegistry $registry,
        public array $diagnostics,
    ) {}

    public function isValid(): bool
    {
        foreach ($this->diagnostics as $diagnostic) {
            if ($diagnostic->severity === DiagnosticSeverity::Error) {
                return false;
            }
        }

        return $this->registry instanceof CompiledRegistry;
    }

    public function requireRegistry(): CompiledRegistry
    {
        if (!$this->isValid()) {
            $errors = array_values(array_filter(
                $this->diagnostics,
                static fn(RegistryDiagnostic $diagnostic): bool => $diagnostic->severity === DiagnosticSeverity::Error,
            ));

            throw new InvalidMessageBusConfiguration(
                'MessageBus registry compilation failed with ' . \count($errors) . ' error(s): '
                    . implode(' | ', array_map(
                        static fn(RegistryDiagnostic $diagnostic): string => $diagnostic->message,
                        $errors,
                    )),
                $this->diagnostics,
            );
        }

        return $this->registry ?? throw new \LogicException('Valid compilation has no registry.');
    }
}

/**
 *  Compiles method attributes and signatures into a deterministic registry.
 *
 * @api tooling
 */
final readonly class RegistryCompiler
{
    /** @param list<RegistryValidationRule> $rules */
    public function __construct(
        private MessageContextFactoryResolver $contextFactories = new MessageContextFactories(),
        private array $rules = [],
    ) {}

    public function compile(RegistryClassSource $source): RegistryCompilationResult
    {
        $bindings = $this->emptyBindings();
        $diagnostics = $this->emptyDiagnostics();
        $messageRoles = $this->emptyRoles();
        $messageNames = [];
        $classes = iterator_to_array($source->classes(), false);
        sort($classes, SORT_STRING);

        foreach (array_values(array_unique($classes)) as $class) {
            if (!class_exists($class)) {
                $diagnostics[] = $this->error(
                    'MBR001',
                    'A registry class supplied by the class source is not autoloadable.',
                    $class,
                    expected: 'class_exists(subject) returns true through the configured Composer autoloader',
                    actual: 'class_exists(subject) returned false',
                );

                continue;
            }

            $reflection = new \ReflectionClass($class);
            $messageClass = $this->messageClass($class);
            if (!$reflection->isInterface() && $messageClass !== null) {
                $role = $this->role($messageClass, $diagnostics, $class);
                if ($role instanceof MessageRole) {
                    $messageRoles[$messageClass] = $role;
                    $bindings[$messageClass] ??= [];
                    if ($role === MessageRole::Command || $role === MessageRole::Event) {
                        $attributes = $reflection->getAttributes(MessageName::class);
                        if (\count($attributes) !== 1) {
                            $diagnostics[] = $this->error(
                                'MBR012',
                                'A durable Command or Event has an invalid #[MessageName] attribute count.',
                                $class,
                                expected: 'exactly 1 #[MessageName] attribute',
                                actual: \count($attributes) . ' #[MessageName] attribute(s)',
                            );
                        } else {
                            try {
                                $name = $attributes[0]->newInstance()->value;
                            } catch (\Throwable $failure) {
                                $diagnostics[] = $this->error(
                                    'MBR020',
                                    'The #[MessageName] attribute could not be constructed for a durable message.',
                                    $class,
                                    expected: 'a #[MessageName] value matching [a-z][a-z0-9._:-]{2,127}',
                                    actual: 'attribute construction threw ' . $failure::class,
                                    causeClass: $failure::class,
                                );

                                continue;
                            }
                            if (isset($messageNames[$name]) && $messageNames[$name] !== $class) {
                                $diagnostics[] = $this->error(
                                    'MBR016',
                                    'Two durable message classes declare the same MessageName value.',
                                    $name,
                                    expected: 'one message class owns this MessageName value',
                                    actual: $messageNames[$name] . ' and ' . $class,
                                );
                            }
                            $messageNames[$name] = $class;
                        }
                    }
                }
            }
            foreach ($reflection->getMethods() as $method) {
                $attributes = $method->getAttributes(Handler::class);
                if ($attributes === []) {
                    continue;
                }

                $this->compileMethod($reflection, $method, $attributes, $bindings, $messageRoles, $diagnostics);
            }
        }

        $this->validateCardinality($bindings, $messageRoles, $diagnostics);
        ksort($bindings, SORT_STRING);
        foreach ($bindings as &$messageBindings) {
            usort($messageBindings, static fn(HandlerBinding $a, HandlerBinding $b): int => $a->id <=> $b->id);
        }
        unset($messageBindings);

        if ($this->hasErrors($diagnostics)) {
            return new RegistryCompilationResult(null, $diagnostics);
        }

        $fingerprintData = [];
        foreach ($bindings as $messageClass => $messageBindings) {
            foreach ($messageBindings as $binding) {
                $fingerprintData[] = [$messageClass, $binding->id, $binding->contextClass];
            }
        }
        $registry = new CompiledRegistry(
            $bindings,
            hash('sha256', json_encode($fingerprintData, JSON_THROW_ON_ERROR)),
        );

        foreach ($this->rules as $rule) {
            array_push($diagnostics, ...$rule->validate($registry));
        }

        return new RegistryCompilationResult(
            $this->hasErrors($diagnostics) ? null : $registry,
            $diagnostics,
        );
    }

    /**
     * @param list<\ReflectionAttribute<Handler>> $attributes
     * @param \ReflectionClass<object> $class
     * @param array<class-string<Message<mixed>>, list<HandlerBinding>> $bindings
     * @param-out array<class-string<Message<mixed>>, list<HandlerBinding>> $bindings
     * @param array<class-string<Message<mixed>>, MessageRole> $messageRoles
     * @param-out array<class-string<Message<mixed>>, MessageRole> $messageRoles
     * @param list<RegistryDiagnostic> $diagnostics
     * @param-out list<RegistryDiagnostic> $diagnostics
     */
    private function compileMethod(
        \ReflectionClass $class,
        \ReflectionMethod $method,
        array $attributes,
        array &$bindings,
        array &$messageRoles,
        array &$diagnostics,
    ): void {
        $symbol = $class->getName() . '::' . $method->getName();
        if (\count($attributes) !== 1 || !$method->isPublic() || $method->isStatic()) {
            $visibility = $method->isPublic() ? 'public' : ($method->isProtected() ? 'protected' : 'private');
            $diagnostics[] = $this->error(
                'MBR002',
                'A method marked as a handler has an invalid attribute count, visibility, or static modifier.',
                $symbol,
                expected: 'exactly 1 #[Handler] attribute on a public non-static method',
                actual: \count($attributes) . ' #[Handler] attribute(s), ' . $visibility . ', '
                    . ($method->isStatic() ? 'static' : 'non-static'),
            );

            return;
        }

        $parameters = $method->getParameters();
        if (\count($parameters) < 1 || \count($parameters) > 2) {
            $diagnostics[] = $this->error(
                'MBR003',
                'A handler method has an invalid number of parameters.',
                $symbol,
                expected: '1 message parameter and optionally 1 MessageContext parameter',
                actual: \count($parameters) . ' parameter(s)',
            );

            return;
        }
        foreach ($parameters as $parameter) {
            if ($parameter->isOptional() || $parameter->isVariadic()) {
                $violations = [];
                if ($parameter->isOptional()) {
                    $violations[] = 'optional';
                }
                if ($parameter->isVariadic()) {
                    $violations[] = 'variadic';
                }
                $diagnostics[] = $this->error(
                    'MBR018',
                    'Handler parameter must be required and non-variadic.',
                    $symbol,
                    expected: 'required non-variadic parameter',
                    actual: '$' . $parameter->getName() . ' is ' . implode(' and ', $violations),
                );

                return;
            }
        }

        $namedMessageClass = $this->namedClass($parameters[0]->getType());
        $messageClass = $namedMessageClass === null ? null : $this->messageClass($namedMessageClass);
        if ($messageClass === null) {
            $diagnostics[] = $this->error(
                'MBR004',
                'The first handler parameter does not resolve to a loadable Message class.',
                $symbol,
                expected: 'one named, autoloadable class implementing Message',
                actual: 'declared first-parameter type is ' . $this->typeName($parameters[0]->getType()),
            );

            return;
        }
        if (!(new \ReflectionClass($messageClass))->isInstantiable()) {
            $diagnostics[] = $this->error(
                'MBR019',
                'Handler message type must be instantiable.',
                $symbol,
                expected: 'concrete instantiable Message class',
                actual: $messageClass,
            );

            return;
        }

        $role = $this->role($messageClass, $diagnostics, $symbol);
        if (!$role instanceof MessageRole) {
            return;
        }
        $messageRoles[$messageClass] = $role;

        $contextClass = null;
        if (isset($parameters[1])) {
            $contextClass = $this->namedClass($parameters[1]->getType());
            if ($contextClass === null || !is_a($contextClass, MessageContext::class, true)) {
                $diagnostics[] = $this->error(
                    'MBR005',
                    'The second handler parameter is not a loadable MessageContext class.',
                    $symbol,
                    expected: 'one named, autoloadable class extending MessageContext',
                    actual: 'declared second-parameter type is ' . $this->typeName($parameters[1]->getType()),
                );

                return;
            }
            if (!$this->contextFactories->has($contextClass)) {
                $diagnostics[] = $this->error(
                    'MBR006',
                    'The handler context type has no exact registered factory.',
                    $contextClass,
                    expected: 'a MessageContextFactory registered for this exact context class',
                    actual: 'no exact factory is registered',
                );

                return;
            }
        }

        $return = $method->getReturnType();
        if (($role === MessageRole::Command || $role === MessageRole::Event)
            && (!$return instanceof \ReflectionNamedType || $return->getName() !== 'void')) {
            $diagnostics[] = $this->error(
                'MBR007',
                'A Command or Event handler has an invalid return type.',
                $symbol,
                expected: 'an explicit void return type',
                actual: 'declared return type is ' . $this->typeName($return),
            );

            return;
        }

        if (($role === MessageRole::Query || $role === MessageRole::Request) && !$return instanceof \ReflectionType) {
            $diagnostics[] = $this->error(
                'MBR008',
                'A Query or Request handler has no declared return type.',
                $symbol,
                expected: 'an explicit return type',
                actual: 'no return type is declared',
            );

            return;
        }

        if ($role === MessageRole::Query && $return instanceof \ReflectionNamedType && $return->getName() === 'void') {
            $diagnostics[] = $this->error(
                'MBR009',
                'A Query handler declares void and therefore cannot produce its query result.',
                $symbol,
                expected: 'a non-void return type matching Query<TResult>',
                actual: 'declared return type is void',
            );

            return;
        }

        try {
            $attribute = $attributes[0]->newInstance();
        } catch (\Throwable $failure) {
            $diagnostics[] = $this->error(
                'MBR017',
                'The #[Handler] attribute could not be constructed for a handler method.',
                $symbol,
                expected: 'a valid #[Handler] attribute; Event subscriptionId matches [a-z0-9][a-z0-9._:-]{2,127}',
                actual: 'attribute construction threw ' . $failure::class,
                causeClass: $failure::class,
            );

            return;
        }
        if ($role === MessageRole::Event && ($attribute->subscriptionId === null || $attribute->subscriptionId === '')) {
            $diagnostics[] = $this->error(
                'MBR010',
                'An Event handler has no stable subscription identity.',
                $symbol,
                expected: 'a non-empty #[Handler(subscriptionId: ...)] value',
                actual: $attribute->subscriptionId === null ? 'subscriptionId is null' : 'subscriptionId is empty',
            );

            return;
        }
        if ($role !== MessageRole::Event && $attribute->subscriptionId !== null) {
            $diagnostics[] = $this->error(
                'MBR011',
                'A non-Event handler declares an Event-only subscriptionId.',
                $symbol,
                expected: 'subscriptionId is null for ' . $role->value . ' handlers',
                actual: 'subscriptionId is "' . $attribute->subscriptionId . '"',
            );

            return;
        }
        if (($role === MessageRole::Command || $role === MessageRole::Event)
            && \count((new \ReflectionClass($messageClass))->getAttributes(MessageName::class)) !== 1) {
            $attributeCount = \count((new \ReflectionClass($messageClass))->getAttributes(MessageName::class));
            $diagnostics[] = $this->error(
                'MBR012',
                'A durable Command or Event has an invalid #[MessageName] attribute count.',
                $messageClass,
                expected: 'exactly 1 #[MessageName] attribute',
                actual: $attributeCount . ' #[MessageName] attribute(s)',
            );

            return;
        }

        $id = $role === MessageRole::Event
            ? $attribute->subscriptionId
            : $messageClass;
        $bindings[$messageClass][] = new HandlerBinding(
            id: (string) $id,
            messageClass: $messageClass,
            role: $role,
            handlerClass: $class->getName(),
            method: $method->getName(),
            contextClass: $contextClass,
            subscriptionId: $attribute->subscriptionId,
        );
    }

    /**
     * @param class-string<Message<mixed>> $messageClass
     * @param list<RegistryDiagnostic> $diagnostics
     * @param-out list<RegistryDiagnostic> $diagnostics
     */
    private function role(string $messageClass, array &$diagnostics, string $symbol): ?MessageRole
    {
        $roles = array_filter([
            MessageRole::Query->value => is_a($messageClass, Query::class, true),
            MessageRole::Request->value => is_a($messageClass, Request::class, true),
            MessageRole::Command->value => is_a($messageClass, Command::class, true),
            MessageRole::Event->value => is_a($messageClass, Event::class, true),
        ]);
        if (\count($roles) !== 1) {
            $actualRoles = array_keys($roles);
            $diagnostics[] = $this->error(
                'MBR013',
                'A Message class does not implement exactly one supported message role.',
                $symbol,
                expected: 'exactly one of Query, Request, Command, or Event',
                actual: $actualRoles === [] ? 'no message role' : implode(', ', $actualRoles),
            );

            return null;
        }

        return MessageRole::from(array_key_first($roles));
    }

    /**
     * @param array<class-string<Message<mixed>>, list<HandlerBinding>> $bindings
     * @param array<class-string<Message<mixed>>, MessageRole> $messageRoles
     * @param list<RegistryDiagnostic> $diagnostics
     * @param-out list<RegistryDiagnostic> $diagnostics
     */
    private function validateCardinality(array $bindings, array $messageRoles, array &$diagnostics): void
    {
        foreach ($bindings as $messageClass => $messageBindings) {
            $role = $messageRoles[$messageClass];
            if ($role !== MessageRole::Event && \count($messageBindings) !== 1) {
                $diagnostics[] = $this->error(
                    'MBR014',
                    'A Query, Request, or Command does not have exactly one compiled handler.',
                    $messageClass,
                    expected: 'exactly 1 compiled handler for role ' . $role->value,
                    actual: \count($messageBindings) . ' compiled handler(s)',
                );
            }

            $ids = array_map(static fn(HandlerBinding $binding): string => $binding->id, $messageBindings);
            if (\count($ids) !== \count(array_unique($ids))) {
                $idCounts = array_count_values($ids);
                $duplicates = array_keys(array_filter($idCounts, static fn(int $count): bool => $count > 1));
                $diagnostics[] = $this->error(
                    'MBR015',
                    'A message has duplicate compiled handler binding identities.',
                    $messageClass,
                    expected: 'every handler binding ID is unique for this message',
                    actual: 'duplicate binding ID(s): ' . implode(', ', $duplicates),
                );
            }
        }
    }

    /** @return class-string|null */
    private function namedClass(?\ReflectionType $type): ?string
    {
        if (!$type instanceof \ReflectionNamedType || $type->isBuiltin()) {
            return null;
        }

        $name = $type->getName();
        if (!class_exists($name) && !interface_exists($name)) {
            return null;
        }

        return $name;
    }

    /** @return class-string<Message<mixed>>|null */
    private function messageClass(string $class): ?string
    {
        if (!class_exists($class) || !is_a($class, Message::class, true)) {
            return null;
        }

        return $class;
    }

    private function typeName(?\ReflectionType $type): string
    {
        return $type instanceof \ReflectionType ? (string) $type : 'not declared';
    }

    private function error(
        string $code,
        string $message,
        ?string $symbol,
        ?string $expected = null,
        ?string $actual = null,
        ?string $causeClass = null,
    ): RegistryDiagnostic {
        return new RegistryDiagnostic(
            $code,
            DiagnosticSeverity::Error,
            $this->detailedMessage($code, $message, $symbol, $expected, $actual, $causeClass),
            $symbol,
            $code,
            'symbol',
            $expected,
            $actual,
            $causeClass,
        );
    }

    private function detailedMessage(
        string $code,
        string $summary,
        ?string $symbol,
        ?string $expected,
        ?string $actual,
        ?string $causeClass,
    ): string {
        $parts = ['[' . $code . '] ' . $summary];
        if ($symbol !== null) {
            $parts[] = 'Subject: ' . $symbol;
        }
        if ($expected !== null) {
            $parts[] = 'Expected: ' . $expected;
        }
        if ($actual !== null) {
            $parts[] = 'Actual: ' . $actual;
        }
        if ($causeClass !== null) {
            $parts[] = 'Cause class: ' . $causeClass;
        }

        return implode(' ', array_map(
            static fn(string $part): string => rtrim($part, ". \t\n\r\0\x0B") . '.',
            $parts,
        ));
    }

    /** @param list<RegistryDiagnostic> $diagnostics */
    private function hasErrors(array $diagnostics): bool
    {
        foreach ($diagnostics as $diagnostic) {
            if ($diagnostic->severity === DiagnosticSeverity::Error) {
                return true;
            }
        }

        return false;
    }

    /** @return array<class-string<Message<mixed>>, list<HandlerBinding>> */
    private function emptyBindings(): array
    {
        return [];
    }

    /** @return array<class-string<Message<mixed>>, MessageRole> */
    private function emptyRoles(): array
    {
        return [];
    }

    /** @return list<RegistryDiagnostic> */
    private function emptyDiagnostics(): array
    {
        return [];
    }
}
