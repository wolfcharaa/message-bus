<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Wolfcharaa\MessageBus\Attribute\Handler;
use Wolfcharaa\MessageBus\Attribute\MessageName;
use Wolfcharaa\MessageBus\Context\MessageContext;
use Wolfcharaa\MessageBus\Contract\Command;
use Wolfcharaa\MessageBus\Contract\Event;
use Wolfcharaa\MessageBus\Contract\Message;
use Wolfcharaa\MessageBus\Contract\Query;
use Wolfcharaa\MessageBus\Delivery\DeliveryPolicies;
use Wolfcharaa\MessageBus\Delivery\DeliveryPolicy;
use Wolfcharaa\MessageBus\Exception\InvalidMessageBusConfiguration;
use Wolfcharaa\MessageBus\Idempotency\AtomicIdempotencyBoundary;
use Wolfcharaa\MessageBus\Idempotency\IdempotencyAttempt;
use Wolfcharaa\MessageBus\Idempotency\IdempotencyIdentity;
use Wolfcharaa\MessageBus\Idempotency\IdempotencyIdentityResolver;
use Wolfcharaa\MessageBus\Idempotency\IdempotencyIdentityResolvers;
use Wolfcharaa\MessageBus\Idempotency\IdempotencyOutcome;
use Wolfcharaa\MessageBus\Idempotency\IdempotencyResult;
use Wolfcharaa\MessageBus\Idempotency\IdempotencyRuntime;
use Wolfcharaa\MessageBus\Idempotency\TargetIdempotencyResolver;
use Wolfcharaa\MessageBus\Observability\FailureStage;
use Wolfcharaa\MessageBus\Registry\DiagnosticSeverity;
use Wolfcharaa\MessageBus\Registry\ExplicitClassSource;
use Wolfcharaa\MessageBus\Registry\RegistryCompilationResult;
use Wolfcharaa\MessageBus\Registry\RegistryCompiler;
use Wolfcharaa\MessageBus\Registry\RegistryDiagnostic;
use Wolfcharaa\MessageBus\Routing\DeliveryMode;
use Wolfcharaa\MessageBus\Routing\DeliveryRoute;
use Wolfcharaa\MessageBus\Routing\DeliveryRoutes;
use Wolfcharaa\MessageBus\Routing\RuntimeConfigurationRule;

final class RegistryDiagnosticsTest extends TestCase
{
    public function testAttributeConstructionDiagnosticsPreserveTheirOriginalThrowable(): void
    {
        foreach ([InvalidAttributeDetailedHandler::class, InvalidDetailedMessageName::class] as $class) {
            $result = (new RegistryCompiler())->compile(new ExplicitClassSource([$class]));
            self::assertInstanceOf(\InvalidArgumentException::class, $result->primaryCause);

            try {
                $result->requireRegistry();
                self::fail('Invalid attribute unexpectedly compiled.');
            } catch (InvalidMessageBusConfiguration $failure) {
                self::assertSame($result->primaryCause, $failure->getPrevious());
            }
        }
    }

    public function testPublishedRegistryAndRuntimeCodesExactlyMatchDetailedScenarioProviders(): void
    {
        $catalog = json_decode(
            (string) file_get_contents(__DIR__ . '/../resources/compatibility/diagnostics.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($catalog);
        self::assertIsArray($catalog['codes'] ?? null);
        self::assertIsArray($catalog['runtime_codes'] ?? null);

        $registryCodes = [];
        foreach (self::provideEveryRegistryDiagnosticIdentifiesRuleSubjectExpectedAndActualStateCases() as $case) {
            $registryCodes[] = $case[0];
        }
        array_push($registryCodes, ...ComposerDirectoryClassSourceTest::diagnosticCodes());
        foreach (self::provideEveryRuntimeConfigurationDiagnosticNamesTargetExpectedAndActualConfigurationCases() as $case) {
            $registryCodes[] = $case[0];
        }

        $runtimeCodes = [];
        foreach (self::provideEveryRuntimeFailureStageHasAnExactStableCodeAndActionableSummaryCases() as $case) {
            $runtimeCodes[] = $case[1];
        }

        self::assertSame($catalog['codes'], $registryCodes);
        self::assertSame($catalog['runtime_codes'], $runtimeCodes);
    }

    /**
     * @param \Closure(): RegistryCompilationResult $compile
     * @param class-string<\Throwable>|null $causeClass
     */
    #[DataProvider('provideEveryRegistryDiagnosticIdentifiesRuleSubjectExpectedAndActualStateCases')]
    public function testEveryRegistryDiagnosticIdentifiesRuleSubjectExpectedAndActualState(
        string $code,
        \Closure $compile,
        string $symbol,
        string $expected,
        string $actual,
        ?string $causeClass = null,
    ): void {
        $this->assertDetailedDiagnostic(
            $this->diagnostic($compile(), $code),
            $code,
            'symbol',
            $symbol,
            $expected,
            $actual,
            $causeClass,
        );
    }

    /**
     * @return iterable<string, array{
     *     string,
     *     \Closure(): RegistryCompilationResult,
     *     string,
     *     string,
     *     string,
     *     5?: class-string<\Throwable>|null
     * }>
     */
    public static function provideEveryRegistryDiagnosticIdentifiesRuleSubjectExpectedAndActualStateCases(): iterable
    {
        yield 'MBR001 class source entry is not autoloadable' => [
            'MBR001',
            static function (): RegistryCompilationResult {
                $source = (new \ReflectionClass(ExplicitClassSource::class))->newInstance([
                    'Application\OutsideComposer\MissingHandler',
                ]);
                if (!$source instanceof ExplicitClassSource) {
                    throw new \LogicException('Reflection did not create ExplicitClassSource.');
                }

                return (new RegistryCompiler())->compile($source);
            },
            'Application\OutsideComposer\MissingHandler',
            'class_exists(subject) returns true through the configured Composer autoloader',
            'class_exists(subject) returned false',
        ];
        yield 'MBR002 handler declaration' => [
            'MBR002',
            self::compile([InvalidDetailedHandler::class]),
            InvalidDetailedHandler::class . '::handle',
            'exactly 1 #[Handler] attribute on a public non-static method',
            '1 #[Handler] attribute(s), public, static',
        ];
        yield 'MBR003 handler parameter count' => [
            'MBR003',
            self::compile([NoParameterDetailedHandler::class]),
            NoParameterDetailedHandler::class . '::__invoke',
            '1 message parameter and optionally 1 MessageContext parameter',
            '0 parameter(s)',
        ];
        yield 'MBR004 first parameter message type' => [
            'MBR004',
            self::compile([NonMessageTypeDetailedHandler::class]),
            NonMessageTypeDetailedHandler::class . '::__invoke',
            'one named, autoloadable class implementing Message',
            'declared first-parameter type is stdClass',
        ];
        yield 'MBR005 second parameter context type' => [
            'MBR005',
            self::compile([InvalidContextTypeDetailedHandler::class]),
            InvalidContextTypeDetailedHandler::class . '::__invoke',
            'one named, autoloadable class extending MessageContext',
            'declared second-parameter type is stdClass',
        ];
        yield 'MBR006 missing exact context factory' => [
            'MBR006',
            self::compile([MissingContextFactoryDetailedHandler::class]),
            UnregisteredDetailedContext::class,
            'a MessageContextFactory registered for this exact context class',
            'no exact factory is registered',
        ];
        yield 'MBR007 durable handler return type' => [
            'MBR007',
            self::compile([InvalidCommandReturnDetailedHandler::class]),
            InvalidCommandReturnDetailedHandler::class . '::__invoke',
            'an explicit void return type',
            'declared return type is string',
        ];
        yield 'MBR008 missing query return type' => [
            'MBR008',
            self::compile([MissingReturnDetailedHandler::class]),
            MissingReturnDetailedHandler::class . '::__invoke',
            'an explicit return type',
            'no return type is declared',
        ];
        yield 'MBR009 void query return type' => [
            'MBR009',
            self::compile([VoidQueryDetailedHandler::class]),
            VoidQueryDetailedHandler::class . '::__invoke',
            'a non-void return type matching Query<TResult>',
            'declared return type is void',
        ];
        yield 'MBR010 event subscription identity' => [
            'MBR010',
            self::compile([MissingSubscriptionDetailedHandler::class]),
            MissingSubscriptionDetailedHandler::class . '::__invoke',
            'a non-empty #[Handler(subscriptionId: ...)] value',
            'subscriptionId is null',
        ];
        yield 'MBR011 non-event subscription identity' => [
            'MBR011',
            self::compile([UnexpectedSubscriptionDetailedHandler::class]),
            UnexpectedSubscriptionDetailedHandler::class . '::__invoke',
            'subscriptionId is null for query handlers',
            'subscriptionId is "query.subscription"',
        ];
        yield 'MBR012 durable message name count' => [
            'MBR012',
            self::compile([MissingMessageNameDetailedHandler::class]),
            MissingNameDetailedCommand::class,
            'exactly 1 #[MessageName] attribute',
            '0 #[MessageName] attribute(s)',
        ];
        yield 'MBR013 message role count' => [
            'MBR013',
            self::compile([NoRoleDetailedMessage::class]),
            NoRoleDetailedMessage::class,
            'exactly one of Query, Request, Command, or Event',
            'no message role',
        ];
        yield 'MBR014 handler cardinality' => [
            'MBR014',
            self::compile([OrphanDetailedQuery::class]),
            OrphanDetailedQuery::class,
            'exactly 1 compiled handler for role query',
            '0 compiled handler(s)',
        ];
        yield 'MBR015 duplicate binding identities' => [
            'MBR015',
            self::compile([
                DuplicateBindingDetailedEvent::class,
                FirstDuplicateBindingDetailedHandler::class,
                SecondDuplicateBindingDetailedHandler::class,
            ]),
            DuplicateBindingDetailedEvent::class,
            'every handler binding ID is unique for this message',
            'duplicate binding ID(s): duplicate.subscription',
        ];
        yield 'MBR016 duplicate durable message name' => [
            'MBR016',
            self::compile([FirstDuplicateNameDetailedEvent::class, SecondDuplicateNameDetailedEvent::class]),
            'duplicate.message.name',
            'one message class owns this MessageName value',
            FirstDuplicateNameDetailedEvent::class . ' and ' . SecondDuplicateNameDetailedEvent::class,
        ];
        yield 'MBR017 invalid handler attribute' => [
            'MBR017',
            self::compile([InvalidAttributeDetailedHandler::class]),
            InvalidAttributeDetailedHandler::class . '::__invoke',
            'a valid #[Handler] attribute; Event subscriptionId matches [a-z0-9][a-z0-9._:-]{2,127}',
            'attribute construction threw InvalidArgumentException',
            \InvalidArgumentException::class,
        ];
        yield 'MBR018 variadic handler parameter' => [
            'MBR018',
            self::compile([VariadicDetailedHandler::class]),
            VariadicDetailedHandler::class . '::__invoke',
            'required non-variadic parameter',
            '$messages is optional and variadic',
        ];
        yield 'MBR019 abstract message type' => [
            'MBR019',
            self::compile([AbstractMessageDetailedHandler::class]),
            AbstractMessageDetailedHandler::class . '::__invoke',
            'concrete instantiable Message class',
            AbstractDetailedQuery::class,
        ];
        yield 'MBR020 invalid message name attribute' => [
            'MBR020',
            self::compile([InvalidDetailedMessageName::class]),
            InvalidDetailedMessageName::class,
            'a #[MessageName] value matching [a-z][a-z0-9._:-]{2,127}',
            'attribute construction threw InvalidArgumentException',
            \InvalidArgumentException::class,
        ];
    }

    /**
     * @param \Closure(): RegistryCompilationResult $compile
     */
    #[DataProvider('provideEveryRuntimeConfigurationDiagnosticNamesTargetExpectedAndActualConfigurationCases')]
    public function testEveryRuntimeConfigurationDiagnosticNamesTargetExpectedAndActualConfiguration(
        string $code,
        \Closure $compile,
        string $symbol,
        string $expected,
        string $actual,
    ): void {
        $this->assertDetailedDiagnostic(
            $this->diagnostic($compile(), $code),
            $code,
            'target',
            $symbol,
            $expected,
            $actual,
        );
    }

    /**
     * @return iterable<string, array{string, \Closure(): RegistryCompilationResult, string, string, string}>
     */
    public static function provideEveryRuntimeConfigurationDiagnosticNamesTargetExpectedAndActualConfigurationCases(): iterable
    {
        $queryTarget = RuntimeDetailedQuery::class;
        $commandTarget = RuntimeDetailedCommand::class;

        yield 'MBC001 unused synchronous route' => [
            'MBC001',
            self::compileRuntimeConfiguration(
                [RuntimeDetailedQuery::class, RuntimeDetailedQueryHandler::class],
                new DeliveryRoutes([$queryTarget => new DeliveryRoute(DeliveryMode::Inline)]),
                new DeliveryPolicies([]),
            ),
            $queryTarget,
            'no DeliveryRoute for a query target',
            'route mode=inline, policy=default, lane=default',
        ];
        yield 'MBC002 missing durable route' => [
            'MBC002',
            self::compileRuntimeConfiguration(
                [RuntimeDetailedCommand::class, RuntimeDetailedCommandHandler::class],
                new DeliveryRoutes([]),
                new DeliveryPolicies([]),
            ),
            $commandTarget,
            'one DeliveryRoute for this command target',
            'no route is configured',
        ];
        yield 'MBC003 non-durable command route' => [
            'MBC003',
            self::compileRuntimeConfiguration(
                [RuntimeDetailedCommand::class, RuntimeDetailedCommandHandler::class],
                new DeliveryRoutes([$commandTarget => new DeliveryRoute(DeliveryMode::Inline)]),
                new DeliveryPolicies([]),
            ),
            $commandTarget,
            'route mode=durable',
            'route mode=inline',
        ];
        yield 'MBC004 unknown delivery policy' => [
            'MBC004',
            self::compileRuntimeConfiguration(
                [RuntimeDetailedCommand::class, RuntimeDetailedCommandHandler::class],
                new DeliveryRoutes([$commandTarget => new DeliveryRoute(DeliveryMode::Durable, 'missing')]),
                new DeliveryPolicies([]),
            ),
            $commandTarget,
            'a registered DeliveryPolicy name',
            'policy "missing" is not registered',
        ];
        yield 'MBC005 missing idempotency runtime' => [
            'MBC005',
            self::compileRuntimeConfiguration(
                [RuntimeDetailedCommand::class, RuntimeDetailedCommandHandler::class],
                new DeliveryRoutes([$commandTarget => new DeliveryRoute(DeliveryMode::Durable, 'idempotent')]),
                new DeliveryPolicies(['idempotent' => new DeliveryPolicy('idempotent', idempotent: true)]),
            ),
            $commandTarget,
            IdempotencyRuntime::class . ' configured with an atomic boundary',
            'IdempotencyRuntime is not configured',
        ];
        yield 'MBC006 lost-attempt retry without idempotency' => [
            'MBC006',
            self::compileRuntimeConfiguration(
                [RuntimeDetailedCommand::class, RuntimeDetailedCommandHandler::class],
                new DeliveryRoutes([$commandTarget => new DeliveryRoute(DeliveryMode::Durable, 'unsafe')]),
                new DeliveryPolicies(['unsafe' => self::unsafeLostRetryPolicy()]),
            ),
            $commandTarget,
            'retryLostAttempts=true requires idempotent=true',
            'retryLostAttempts=true and idempotent=false',
        ];
        yield 'MBC007 resolver for unknown target' => [
            'MBC007',
            self::compileRuntimeConfiguration(
                [RuntimeDetailedCommand::class, RuntimeDetailedCommandHandler::class],
                new DeliveryRoutes([$commandTarget => new DeliveryRoute(DeliveryMode::Durable)]),
                new DeliveryPolicies(['default' => new DeliveryPolicy('default')]),
                self::idempotencyRuntime(['unknown.target']),
            ),
            'unknown.target',
            'resolver target ID exists in the compiled registry',
            'target ID is not compiled',
        ];
        yield 'MBC008 missing target-scoped resolver' => [
            'MBC008',
            self::compileRuntimeConfiguration(
                [RuntimeDetailedCommand::class, RuntimeDetailedCommandHandler::class],
                new DeliveryRoutes([$commandTarget => new DeliveryRoute(DeliveryMode::Durable, 'idempotent')]),
                new DeliveryPolicies(['idempotent' => new DeliveryPolicy('idempotent', idempotent: true)]),
                self::idempotencyRuntime([]),
            ),
            $commandTarget,
            'resolver for target ' . $commandTarget,
            'no resolver is registered for this target',
        ];
    }

    #[DataProvider('provideEveryRuntimeFailureStageHasAnExactStableCodeAndActionableSummaryCases')]
    public function testEveryRuntimeFailureStageHasAnExactStableCodeAndActionableSummary(
        FailureStage $stage,
        string $code,
        string $summary,
    ): void {
        self::assertSame($code, $stage->code());
        self::assertSame($summary, $stage->summary());
        self::assertStringContainsString($stage->code(), $stage->summary());
    }

    /** @return iterable<string, array{FailureStage, string, string}> */
    public static function provideEveryRuntimeFailureStageHasAnExactStableCodeAndActionableSummaryCases(): iterable
    {
        yield 'dispatch' => [FailureStage::Dispatch, 'MBD001', '[MBD001] Message dispatch failed before a result was returned.'];
        yield 'payload decode' => [FailureStage::PayloadDecode, 'MBD101', '[MBD101] A persisted message payload could not be decoded into its message class.'];
        yield 'target resolution' => [FailureStage::TargetResolution, 'MBD102', '[MBD102] A persisted delivery target could not be resolved in the compiled registry.'];
        yield 'context creation' => [FailureStage::ContextCreation, 'MBD103', '[MBD103] The MessageContext required by a compiled handler could not be created.'];
        yield 'handler resolution' => [FailureStage::HandlerResolution, 'MBD104', '[MBD104] The compiled handler class could not be resolved by the DI container.'];
        yield 'handler execution' => [FailureStage::HandlerExecution, 'MBD105', '[MBD105] The resolved handler threw while processing the message.'];
        yield 'idempotency' => [FailureStage::Idempotency, 'MBD106', '[MBD106] The idempotency boundary or identity resolution failed.'];
        yield 'failure classification' => [FailureStage::FailureClassification, 'MBD107', '[MBD107] The application failure classifier threw while classifying another failure.'];
        yield 'delivery settlement' => [FailureStage::DeliverySettlement, 'MBD108', '[MBD108] The backend could not persist the delivery failure outcome.'];
        yield 'worker fork' => [FailureStage::WorkerFork, 'MBD109', '[MBD109] The worker supervisor could not create a child process.'];
        yield 'lease recovery' => [FailureStage::LeaseRecovery, 'MBD110', '[MBD110] Lease recovery found that the worker no longer owns the delivery attempt.'];
        yield 'delivery acceptance' => [FailureStage::DeliveryAcceptance, 'MBD111', '[MBD111] The durable backend did not accept the Command or Event delivery.'];
    }

    /** @param list<class-string> $classes @return \Closure(): RegistryCompilationResult */
    private static function compile(array $classes): \Closure
    {
        return static fn(): RegistryCompilationResult => (new RegistryCompiler())
            ->compile(new ExplicitClassSource($classes));
    }

    /**
     * @param list<class-string> $classes
     * @return \Closure(): RegistryCompilationResult
     */
    private static function compileRuntimeConfiguration(
        array $classes,
        DeliveryRoutes $routes,
        DeliveryPolicies $policies,
        ?IdempotencyRuntime $idempotency = null,
    ): \Closure {
        return static fn(): RegistryCompilationResult => (new RegistryCompiler(rules: [
            new RuntimeConfigurationRule($routes, $policies, $idempotency),
        ]))->compile(new ExplicitClassSource($classes));
    }

    /** @param list<string> $targetIds */
    private static function idempotencyRuntime(array $targetIds): IdempotencyRuntime
    {
        $resolver = new DetailedIdentityResolver();

        return new IdempotencyRuntime(
            new IdempotencyIdentityResolvers(array_map(
                static fn(string $targetId): TargetIdempotencyResolver => new TargetIdempotencyResolver(
                    $targetId,
                    $resolver,
                ),
                $targetIds,
            )),
            new DetailedAtomicBoundary(),
        );
    }

    private static function unsafeLostRetryPolicy(): DeliveryPolicy
    {
        $reflection = new \ReflectionClass(DeliveryPolicy::class);
        $policy = $reflection->newInstanceWithoutConstructor();
        if (!$policy instanceof DeliveryPolicy) {
            throw new \LogicException('Reflection did not create DeliveryPolicy.');
        }
        foreach ([
            'name' => 'unsafe',
            'maxAttempts' => 2,
            'retryDelaySeconds' => 0,
            'leaseSeconds' => 60,
            'maxElapsedSeconds' => 300,
            'priority' => 0,
            'idempotent' => false,
            'idempotencyRetentionSeconds' => 86_400,
            'replayWindowSeconds' => 0,
            'idempotencySafetyMarginSeconds' => 60,
            'retryLostAttempts' => true,
        ] as $property => $value) {
            $reflection->getProperty($property)->setValue($policy, $value);
        }

        return $policy;
    }

    private function assertDetailedDiagnostic(
        RegistryDiagnostic $diagnostic,
        string $code,
        string $subjectKind,
        string $symbol,
        string $expected,
        string $actual,
        ?string $causeClass = null,
    ): void {
        self::assertSame($code, $diagnostic->code);
        self::assertSame(DiagnosticSeverity::Error, $diagnostic->severity);
        self::assertSame($code, $diagnostic->rule);
        self::assertSame($subjectKind, $diagnostic->subjectKind);
        self::assertSame($symbol, $diagnostic->symbol);
        self::assertSame($expected, $diagnostic->expected);
        self::assertSame($actual, $diagnostic->actual);
        self::assertSame($causeClass, $diagnostic->causeClass);
        self::assertStringContainsString('[' . $code . ']', $diagnostic->message);
        self::assertStringContainsString($symbol, $diagnostic->message);
        self::assertStringContainsString($expected, $diagnostic->message);
        self::assertStringContainsString($actual, $diagnostic->message);
        if ($causeClass !== null) {
            self::assertStringContainsString($causeClass, $diagnostic->message);
        }
        self::assertSame($diagnostic->message, $diagnostic->normalized()['message']);
    }

    private function diagnostic(RegistryCompilationResult $result, string $code): RegistryDiagnostic
    {
        $matches = array_values(array_filter(
            $result->diagnostics,
            static fn(RegistryDiagnostic $diagnostic): bool => $diagnostic->code === $code,
        ));
        self::assertCount(1, $matches, 'Expected exactly one registry diagnostic ' . $code . '.');

        return $matches[0];
    }
}

/** @implements Query<string> */
final readonly class DetailedHandlerQuery implements Query {}

final readonly class InvalidDetailedHandler
{
    #[Handler]
    public static function handle(DetailedHandlerQuery $query): string
    {
        return $query::class;
    }
}

final readonly class NoParameterDetailedHandler
{
    #[Handler]
    public function __invoke(): string
    {
        return 'invalid';
    }
}

final readonly class NonMessageTypeDetailedHandler
{
    #[Handler]
    public function __invoke(\stdClass $message): string
    {
        return $message::class;
    }
}

final readonly class InvalidContextTypeDetailedHandler
{
    #[Handler]
    public function __invoke(DetailedHandlerQuery $query, \stdClass $context): string
    {
        return $query::class . $context::class;
    }
}

interface UnregisteredDetailedContext extends MessageContext {}

final readonly class MissingContextFactoryDetailedHandler
{
    #[Handler]
    public function __invoke(DetailedHandlerQuery $query, UnregisteredDetailedContext $context): string
    {
        return $query::class . $context::class;
    }
}

#[MessageName('invalid.return.command')]
final readonly class InvalidReturnDetailedCommand implements Command {}

final readonly class InvalidCommandReturnDetailedHandler
{
    #[Handler]
    public function __invoke(InvalidReturnDetailedCommand $command): string
    {
        return $command::class;
    }
}

final readonly class MissingReturnDetailedHandler
{
    /** @return string */
    #[Handler]
    public function __invoke(DetailedHandlerQuery $query)
    {
        return $query::class;
    }
}

final readonly class VoidQueryDetailedHandler
{
    #[Handler]
    public function __invoke(DetailedHandlerQuery $query): void {}
}

#[MessageName('missing.subscription.event')]
final readonly class MissingSubscriptionDetailedEvent implements Event {}

final readonly class MissingSubscriptionDetailedHandler
{
    #[Handler]
    public function __invoke(MissingSubscriptionDetailedEvent $event): void {}
}

final readonly class UnexpectedSubscriptionDetailedHandler
{
    #[Handler('query.subscription')]
    public function __invoke(DetailedHandlerQuery $query): string
    {
        return $query::class;
    }
}

final readonly class MissingNameDetailedCommand implements Command {}

final readonly class MissingMessageNameDetailedHandler
{
    #[Handler]
    public function __invoke(MissingNameDetailedCommand $command): void {}
}

/** @implements Message<mixed> */
final readonly class NoRoleDetailedMessage implements Message {}

/** @implements Query<string> */
final readonly class OrphanDetailedQuery implements Query {}

#[MessageName('duplicate.binding.event')]
final readonly class DuplicateBindingDetailedEvent implements Event {}

final readonly class FirstDuplicateBindingDetailedHandler
{
    #[Handler('duplicate.subscription')]
    public function __invoke(DuplicateBindingDetailedEvent $event): void {}
}

final readonly class SecondDuplicateBindingDetailedHandler
{
    #[Handler('duplicate.subscription')]
    public function __invoke(DuplicateBindingDetailedEvent $event): void {}
}

#[MessageName('duplicate.message.name')]
final readonly class FirstDuplicateNameDetailedEvent implements Event {}

#[MessageName('duplicate.message.name')]
final readonly class SecondDuplicateNameDetailedEvent implements Event {}

#[MessageName('invalid.attribute.event')]
final readonly class InvalidAttributeDetailedEvent implements Event {}

final readonly class InvalidAttributeDetailedHandler
{
    #[Handler('Invalid Attribute')]
    public function __invoke(InvalidAttributeDetailedEvent $event): void {}
}

final readonly class VariadicDetailedHandler
{
    #[Handler]
    public function __invoke(DetailedHandlerQuery ...$messages): string
    {
        return (string) \count($messages);
    }
}

/** @implements Query<string> */
abstract class AbstractDetailedQuery implements Query {}

final readonly class AbstractMessageDetailedHandler
{
    #[Handler]
    public function __invoke(AbstractDetailedQuery $query): string
    {
        return $query::class;
    }
}

#[MessageName('Invalid Name')]
final readonly class InvalidDetailedMessageName implements Command {}

/** @implements Query<string> */
final readonly class RuntimeDetailedQuery implements Query {}

final readonly class RuntimeDetailedQueryHandler
{
    #[Handler]
    public function __invoke(RuntimeDetailedQuery $query): string
    {
        return $query::class;
    }
}

#[MessageName('runtime.detailed.command')]
final readonly class RuntimeDetailedCommand implements Command {}

final readonly class RuntimeDetailedCommandHandler
{
    #[Handler]
    public function __invoke(RuntimeDetailedCommand $command): void {}
}

final readonly class DetailedIdentityResolver implements IdempotencyIdentityResolver
{
    public function resolve(Message $message, string $targetId): IdempotencyIdentity
    {
        throw new \LogicException('Resolver is not invoked during registry validation.');
    }
}

final readonly class DetailedAtomicBoundary implements AtomicIdempotencyBoundary
{
    public function execute(IdempotencyAttempt $attempt, callable $effect): IdempotencyResult
    {
        return new IdempotencyResult(IdempotencyOutcome::Executed);
    }
}
