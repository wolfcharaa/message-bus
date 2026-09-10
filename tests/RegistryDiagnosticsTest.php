<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Tests;

use PHPUnit\Framework\TestCase;
use Wolfcharaa\MessageBus\Attribute\CacheResult;
use Wolfcharaa\MessageBus\Attribute\CommandHandler;
use Wolfcharaa\MessageBus\Attribute\EventSubscriber;
use Wolfcharaa\MessageBus\Attribute\MessageAlias;
use Wolfcharaa\MessageBus\Attribute\QueryHandler;
use Wolfcharaa\MessageBus\Context\MessageContextFactoryInterface;
use Wolfcharaa\MessageBus\Context\MessageContextInterface;
use Wolfcharaa\MessageBus\Discovery\AttributeHandlerDiscovery;
use Wolfcharaa\MessageBus\Discovery\ClassListProvider;
use Wolfcharaa\MessageBus\Envelope\Envelope;
use Wolfcharaa\MessageBus\Execution\HandlerExecutionResultInterface;
use Wolfcharaa\MessageBus\Flow\FlowDefinition;
use Wolfcharaa\MessageBus\Flow\FlowRegistry;
use Wolfcharaa\MessageBus\Interceptor\PipelineInterface as InterceptorPipelineInterface;
use Wolfcharaa\MessageBus\Interceptor\PipelineInterface;
use Wolfcharaa\MessageBus\MessageBusInterface;
use Wolfcharaa\MessageBus\PublishOptions;
use Wolfcharaa\MessageBus\PublishResult;
use Wolfcharaa\MessageBus\Registry\MessageRegistryCompiler;
use Wolfcharaa\MessageBus\Registry\MessageRegistryCompilerOptions;
use Wolfcharaa\MessageBus\Registry\RegistryCompilationException;
use Wolfcharaa\MessageBus\Registry\RegistryCompilationGraphContext;
use Wolfcharaa\MessageBus\Registry\RegistryCompilationStage;
use Wolfcharaa\MessageBus\Registry\RegistryDiagnostic;
use Wolfcharaa\MessageBus\Registry\RegistryDiagnosticCodes;
use Wolfcharaa\MessageBus\Registry\RegistryDiagnosticSeverity;
use Wolfcharaa\MessageBus\Registry\RegistryValidationRuleInterface;
use Wolfcharaa\MessageBus\Worker\WorkerRuntimeControlInterface;

final class RegistryDiagnosticsTest extends TestCase
{
    public function testDiscoverWithDiagnosticsReportsDuplicateAliasWithoutThrowing(): void
    {
        $result = (new AttributeHandlerDiscovery())->discoverWithDiagnostics(new ClassListProvider([
            DiagnosticsDuplicateAliasFirstMessage::class,
            DiagnosticsDuplicateAliasSecondMessage::class,
        ]));

        self::assertTrue($result->hasErrors());
        self::assertDiagnosticCode($result->diagnostics, RegistryDiagnosticCodes::ALIAS_DUPLICATE);
        self::assertSame(DiagnosticsDuplicateAliasFirstMessage::class, $result->aliases['diagnostics.duplicate']);
    }

    public function testDiscoverWithDiagnosticsReportsMultipleAliasesWithoutInstantiatingRepeatedAttribute(): void
    {
        $result = (new AttributeHandlerDiscovery())->discoverWithDiagnostics(new ClassListProvider([
            DiagnosticsMultipleAliasMessage::class,
        ]));

        self::assertTrue($result->hasErrors());
        self::assertDiagnosticCode($result->diagnostics, RegistryDiagnosticCodes::ALIAS_MULTIPLE_FOR_MESSAGE);
        self::assertSame([], $result->aliases);
    }

    public function testDiscoverWithDiagnosticsReportsCacheBindingIdAmbiguity(): void
    {
        $result = (new AttributeHandlerDiscovery())->discoverWithDiagnostics(new ClassListProvider([
            DiagnosticsCacheMessageA::class,
            DiagnosticsCacheMessageB::class,
            DiagnosticsAmbiguousCacheHandler::class,
        ]));

        self::assertTrue($result->hasErrors());
        self::assertCount(2, $result->bindings);
        self::assertDiagnosticCode($result->diagnostics, RegistryDiagnosticCodes::CACHE_BINDING_ID_REQUIRED);
    }

    public function testCompileWithDiagnosticsReportsAsyncBindingWithoutStableId(): void
    {
        $result = (new MessageRegistryCompiler())->compileWithDiagnostics(
            new ClassListProvider([
                DiagnosticsAsyncWithoutBindingIdMessage::class,
                DiagnosticsAsyncWithoutBindingIdHandler::class,
            ]),
            new FlowRegistry(FlowDefinition::async('async')->transport('database', 'queue')),
        );

        self::assertFalse($result->hasDefinition());
        self::assertSame(RegistryCompilationStage::BindingsNormalized, $result->graphContext?->stage);
        self::assertDiagnosticCode($result->diagnostics, RegistryDiagnosticCodes::BINDING_MISSING_ID);
    }

    public function testCompileWithDiagnosticsReportsDuplicateBindingId(): void
    {
        $result = (new MessageRegistryCompiler())->compileWithDiagnostics(new ClassListProvider([
            DiagnosticsDuplicateBindingEvent::class,
            DiagnosticsDuplicateBindingHandlerA::class,
            DiagnosticsDuplicateBindingHandlerB::class,
        ]));

        self::assertFalse($result->hasDefinition());
        self::assertDiagnosticCode($result->diagnostics, RegistryDiagnosticCodes::BINDING_DUPLICATE_ID);
    }

    public function testCompileWithDiagnosticsReportsMissingFlow(): void
    {
        $result = (new MessageRegistryCompiler())->compileWithDiagnostics(new ClassListProvider([
            DiagnosticsMissingFlowMessage::class,
            DiagnosticsMissingFlowHandler::class,
        ]));

        self::assertFalse($result->hasDefinition());
        self::assertDiagnosticCode($result->diagnostics, RegistryDiagnosticCodes::FLOW_MISSING);
    }

    public function testCompileWithDiagnosticsReportsInvalidHandlerSignature(): void
    {
        $result = (new MessageRegistryCompiler())->compileWithDiagnostics(new ClassListProvider([
            DiagnosticsInvalidHandlerMessage::class,
            DiagnosticsInvalidHandler::class,
        ]));

        self::assertFalse($result->hasDefinition());
        self::assertDiagnosticCode($result->diagnostics, RegistryDiagnosticCodes::HANDLER_INVALID_SIGNATURE);
    }

    public function testCompileWithDiagnosticsReportsMessageAndHandlerArgumentMismatch(): void
    {
        $result = (new MessageRegistryCompiler())->compileWithDiagnostics(new ClassListProvider([
            DiagnosticsExpectedMessage::class,
            DiagnosticsWrongMessage::class,
            DiagnosticsMismatchedHandler::class,
        ]));

        self::assertFalse($result->hasDefinition());
        $diagnostic = self::diagnostic($result->diagnostics, RegistryDiagnosticCodes::HANDLER_INVALID_SIGNATURE);
        self::assertNotNull($diagnostic);
        self::assertStringContainsString('First argument', $diagnostic->message);
        self::assertSame(DiagnosticsExpectedMessage::class, $diagnostic->target?->messageClass);
        self::assertSame(DiagnosticsMismatchedHandler::class, $diagnostic->target?->handlerClass);
        self::assertSame(RegistryCompilationStage::CoreValidated, $result->graphContext?->stage);
        self::assertCount(1, $result->graphContext?->bindings ?? []);
    }

    public function testCompileWithDiagnosticsReportsMultipleQueryHandlers(): void
    {
        $result = (new MessageRegistryCompiler())->compileWithDiagnostics(new ClassListProvider([
            DiagnosticsDuplicateQueryMessage::class,
            DiagnosticsDuplicateQueryHandlerA::class,
            DiagnosticsDuplicateQueryHandlerB::class,
        ]));

        self::assertFalse($result->hasDefinition());
        self::assertDiagnosticCode($result->diagnostics, RegistryDiagnosticCodes::QUERY_HANDLER_COUNT);
    }

    public function testCompileWithDiagnosticsReportsCommandPrimaryMissingAndDuplicate(): void
    {
        $missing = (new MessageRegistryCompiler())->compileWithDiagnostics(new ClassListProvider([
            DiagnosticsPrimaryMissingCommand::class,
            DiagnosticsPrimaryMissingHandlerA::class,
            DiagnosticsPrimaryMissingHandlerB::class,
        ]));

        self::assertFalse($missing->hasDefinition());
        self::assertDiagnosticCode($missing->diagnostics, RegistryDiagnosticCodes::COMMAND_PRIMARY_MISSING);

        $duplicate = (new MessageRegistryCompiler())->compileWithDiagnostics(new ClassListProvider([
            DiagnosticsPrimaryDuplicateCommand::class,
            DiagnosticsPrimaryDuplicateHandlerA::class,
            DiagnosticsPrimaryDuplicateHandlerB::class,
        ]));

        self::assertFalse($duplicate->hasDefinition());
        self::assertDiagnosticCode($duplicate->diagnostics, RegistryDiagnosticCodes::COMMAND_PRIMARY_DUPLICATE);
    }

    public function testCompileWithDiagnosticsReportsQueryBoundToAsyncFlow(): void
    {
        $result = (new MessageRegistryCompiler())->compileWithDiagnostics(
            new ClassListProvider([
                DiagnosticsAsyncQueryMessage::class,
                DiagnosticsAsyncQueryHandler::class,
            ]),
            new FlowRegistry(FlowDefinition::async('async')->transport('database', 'queue')),
        );

        self::assertFalse($result->hasDefinition());
        self::assertDiagnosticCode($result->diagnostics, RegistryDiagnosticCodes::QUERY_ASYNC_FLOW);
    }

    public function testCompileWithDiagnosticsRejectsQueryAndPrimaryCommandForSameMessage(): void
    {
        $result = (new MessageRegistryCompiler())->compileWithDiagnostics(new ClassListProvider([
            DiagnosticsMixedDispatchMessage::class,
            DiagnosticsMixedDispatchQueryHandler::class,
            DiagnosticsMixedDispatchCommandHandler::class,
        ]));

        self::assertFalse($result->hasDefinition());
        self::assertDiagnosticCode($result->diagnostics, RegistryDiagnosticCodes::MESSAGE_KIND_CONFLICT);
    }

    public function testCompileWithDiagnosticsReportsInvalidInterceptorSignature(): void
    {
        $result = (new MessageRegistryCompiler())->compileWithDiagnostics(new ClassListProvider([
            DiagnosticsInvalidInterceptorMessage::class,
            DiagnosticsInvalidInterceptorHandler::class,
        ]));

        self::assertFalse($result->hasDefinition());
        self::assertDiagnosticCode($result->diagnostics, RegistryDiagnosticCodes::INTERCEPTOR_INVALID_SIGNATURE);
    }

    public function testCompileAcceptsNewInterceptorPipelineInterface(): void
    {
        $result = (new MessageRegistryCompiler())->compileWithDiagnostics(
            new ClassListProvider([
                DiagnosticsNewInterceptorMessage::class,
                DiagnosticsNewInterceptorHandler::class,
            ]),
        );

        self::assertTrue($result->hasDefinition());
        self::assertFalse($result->hasErrors());
    }

    public function testLegacyCompileThrowsExceptionWithTypedDiagnostics(): void
    {
        try {
            (new MessageRegistryCompiler())->compile(new ClassListProvider([
                DiagnosticsDuplicateAliasFirstMessage::class,
                DiagnosticsDuplicateAliasSecondMessage::class,
            ]));
        } catch (RegistryCompilationException $exception) {
            self::assertTrue($exception->hasErrors());
            self::assertDiagnosticCode($exception->diagnostics(), RegistryDiagnosticCodes::ALIAS_DUPLICATE);
            self::assertStringContainsString('Duplicate MessageAlias', $exception->getMessage());

            return;
        }

        self::fail('Expected RegistryCompilationException.');
    }

    public function testProjectValidationRuleCanEmitWarningsAndFailOnWarningControlsPolicy(): void
    {
        $compiler = new MessageRegistryCompiler(validationRules: [new DiagnosticsWarningRule()]);
        $result = $compiler->compileWithDiagnostics(new ClassListProvider([
            DiagnosticsProjectRuleMessage::class,
            DiagnosticsProjectRuleHandler::class,
        ]));

        self::assertTrue($result->hasDefinition());
        self::assertTrue($result->hasWarnings());
        self::assertFalse($result->isFailure(new MessageRegistryCompilerOptions()));
        self::assertTrue($result->isFailure(new MessageRegistryCompilerOptions(failOnWarning: true)));
    }

    public function testProjectValidationRuleCanFailCompilationWithGraphContext(): void
    {
        $compiler = new MessageRegistryCompiler(validationRules: [new DiagnosticsErrorRule()]);
        $result = $compiler->compileWithDiagnostics(new ClassListProvider([
            DiagnosticsProjectRuleMessage::class,
            DiagnosticsProjectRuleHandler::class,
        ]));

        self::assertFalse($result->hasDefinition());
        self::assertSame(RegistryCompilationStage::ProjectRulesValidated, $result->graphContext?->stage);
        self::assertDiagnosticCode($result->diagnostics, 'project.error');
    }

    public function testProjectValidationRuleMustImplementContract(): void
    {
        $compiler = new MessageRegistryCompiler(validationRules: [new \stdClass()]);

        $this->expectException(RegistryCompilationException::class);
        $this->expectExceptionMessage('must implement `' . RegistryValidationRuleInterface::class . '`');

        $compiler->compileWithDiagnostics(new ClassListProvider([
            DiagnosticsProjectRuleMessage::class,
            DiagnosticsProjectRuleHandler::class,
        ]));
    }

    public function testProjectValidationRuleMustReturnDiagnostics(): void
    {
        $compiler = new MessageRegistryCompiler(validationRules: [new DiagnosticsInvalidRuleResult()]);

        $this->expectException(RegistryCompilationException::class);
        $this->expectExceptionMessage('must return only `' . RegistryDiagnostic::class . '` instances');

        $compiler->compileWithDiagnostics(new ClassListProvider([
            DiagnosticsProjectRuleMessage::class,
            DiagnosticsProjectRuleHandler::class,
        ]));
    }

    public function testCompileWithDiagnosticsReportsInvalidFlowDefinitions(): void
    {
        $compiler = new MessageRegistryCompiler();

        $missingContext = $compiler->compileWithDiagnostics(
            new ClassListProvider([DiagnosticsFlowMessage::class, DiagnosticsFlowHandler::class]),
            new FlowRegistry(FlowDefinition::sync('default')->context('MissingFlowContext')),
        );
        self::assertDiagnosticCode($missingContext->diagnostics, RegistryDiagnosticCodes::FLOW_INVALID);

        $invalidContext = $compiler->compileWithDiagnostics(
            new ClassListProvider([DiagnosticsFlowMessage::class, DiagnosticsFlowHandler::class]),
            new FlowRegistry(FlowDefinition::sync('default')->context(DiagnosticsNotAContext::class, DiagnosticsContextFactory::class)),
        );
        self::assertDiagnosticCode($invalidContext->diagnostics, RegistryDiagnosticCodes::FLOW_INVALID);

        $missingFactory = $compiler->compileWithDiagnostics(
            new ClassListProvider([DiagnosticsFlowMessage::class, DiagnosticsFlowHandler::class]),
            new FlowRegistry(FlowDefinition::sync('default')->context(DiagnosticsCustomContext::class)),
        );
        self::assertDiagnosticCode($missingFactory->diagnostics, RegistryDiagnosticCodes::FLOW_INVALID);

        $invalidFactory = $compiler->compileWithDiagnostics(
            new ClassListProvider([DiagnosticsFlowMessage::class, DiagnosticsFlowHandler::class]),
            new FlowRegistry(FlowDefinition::sync('default')->context(DiagnosticsCustomContext::class, DiagnosticsNotAContext::class)),
        );
        self::assertDiagnosticCode($invalidFactory->diagnostics, RegistryDiagnosticCodes::FLOW_INVALID);

        $missingStrategy = $compiler->compileWithDiagnostics(
            new ClassListProvider([DiagnosticsFlowMessage::class, DiagnosticsFlowHandler::class]),
            new FlowRegistry(FlowDefinition::sync('default')->strategy('MissingExecutionStrategy')),
        );
        self::assertDiagnosticCode($missingStrategy->diagnostics, RegistryDiagnosticCodes::FLOW_INVALID);

        $asyncWithoutTransport = $compiler->compileWithDiagnostics(
            new ClassListProvider([DiagnosticsFlowMessage::class, DiagnosticsFlowAsyncHandler::class]),
            new FlowRegistry(FlowDefinition::async('async')),
        );
        self::assertDiagnosticCode($asyncWithoutTransport->diagnostics, RegistryDiagnosticCodes::FLOW_INVALID);
    }

    public function testCompileWithDiagnosticsReportsHandlerSignatureVariants(): void
    {
        $compiler = new MessageRegistryCompiler();

        $noArguments = $compiler->compileWithDiagnostics(new ClassListProvider([
            DiagnosticsNoArgumentsMessage::class,
            DiagnosticsNoArgumentsHandler::class,
        ]));
        self::assertDiagnosticCode($noArguments->diagnostics, RegistryDiagnosticCodes::HANDLER_INVALID_SIGNATURE);

        $contextlessWithContext = $compiler->compileWithDiagnostics(new ClassListProvider([
            DiagnosticsContextlessMessage::class,
            DiagnosticsContextlessWithContextHandler::class,
        ]));
        self::assertDiagnosticCode($contextlessWithContext->diagnostics, RegistryDiagnosticCodes::HANDLER_INVALID_SIGNATURE);

        $queryWithoutReturn = $compiler->compileWithDiagnostics(new ClassListProvider([
            DiagnosticsQueryWithoutReturnMessage::class,
            DiagnosticsQueryWithoutReturnHandler::class,
        ]));
        self::assertDiagnosticCode($queryWithoutReturn->diagnostics, RegistryDiagnosticCodes::HANDLER_INVALID_SIGNATURE);

        $commandWithReturn = $compiler->compileWithDiagnostics(new ClassListProvider([
            DiagnosticsCommandWithReturnMessage::class,
            DiagnosticsCommandWithReturnHandler::class,
        ]));
        self::assertDiagnosticCode($commandWithReturn->diagnostics, RegistryDiagnosticCodes::HANDLER_INVALID_SIGNATURE);

        $eventWithReturn = $compiler->compileWithDiagnostics(new ClassListProvider([
            DiagnosticsEventWithReturnMessage::class,
            DiagnosticsEventWithReturnHandler::class,
        ]));
        self::assertDiagnosticCode($eventWithReturn->diagnostics, RegistryDiagnosticCodes::HANDLER_INVALID_SIGNATURE);
    }

    public function testCompileWithDiagnosticsReportsInterceptorSignatureVariants(): void
    {
        $compiler = new MessageRegistryCompiler();

        $missingInvoke = $compiler->compileWithDiagnostics(new ClassListProvider([
            DiagnosticsMissingInterceptorMessage::class,
            DiagnosticsMissingInterceptorHandler::class,
        ]));
        self::assertDiagnosticCode($missingInvoke->diagnostics, RegistryDiagnosticCodes::INTERCEPTOR_INVALID_SIGNATURE);

        $tooFewArguments = $compiler->compileWithDiagnostics(new ClassListProvider([
            DiagnosticsTooFewInterceptorMessage::class,
            DiagnosticsTooFewInterceptorHandler::class,
        ]));
        self::assertDiagnosticCode($tooFewArguments->diagnostics, RegistryDiagnosticCodes::INTERCEPTOR_INVALID_SIGNATURE);

        $wrongPipeline = $compiler->compileWithDiagnostics(new ClassListProvider([
            DiagnosticsWrongPipelineInterceptorMessage::class,
            DiagnosticsWrongPipelineInterceptorHandler::class,
        ]));
        self::assertDiagnosticCode($wrongPipeline->diagnostics, RegistryDiagnosticCodes::INTERCEPTOR_INVALID_SIGNATURE);
    }

    /** @param list<RegistryDiagnostic> $diagnostics */
    private static function assertDiagnosticCode(array $diagnostics, string $code): void
    {
        $diagnostic = self::diagnostic($diagnostics, $code);
        if ($diagnostic !== null) {
            self::assertSame(RegistryDiagnosticSeverity::Error, $diagnostic->severity);

            return;
        }

        self::fail('Diagnostic code not found: ' . $code);
    }

    /** @param list<RegistryDiagnostic> $diagnostics */
    private static function diagnostic(array $diagnostics, string $code): ?RegistryDiagnostic
    {
        foreach ($diagnostics as $diagnostic) {
            if ($diagnostic->code === $code) {
                return $diagnostic;
            }
        }

        return null;
    }
}

#[MessageAlias('diagnostics.duplicate')]
final class DiagnosticsDuplicateAliasFirstMessage
{
}

#[MessageAlias('diagnostics.duplicate')]
final class DiagnosticsDuplicateAliasSecondMessage
{
}

#[MessageAlias('diagnostics.multiple.one')]
#[MessageAlias('diagnostics.multiple.two')]
final class DiagnosticsMultipleAliasMessage
{
}

final class DiagnosticsCacheMessageA
{
}

final class DiagnosticsCacheMessageB
{
}

#[CacheResult(ttlSeconds: 60)]
#[QueryHandler(message: DiagnosticsCacheMessageA::class, method: 'handleA')]
#[QueryHandler(message: DiagnosticsCacheMessageB::class, method: 'handleB')]
final class DiagnosticsAmbiguousCacheHandler
{
    public function handleA(DiagnosticsCacheMessageA $message, MessageContextInterface $context): string
    {
        return 'a';
    }

    public function handleB(DiagnosticsCacheMessageB $message, MessageContextInterface $context): string
    {
        return 'b';
    }
}

#[MessageAlias('diagnostics.async_without_binding_id')]
final class DiagnosticsAsyncWithoutBindingIdMessage
{
}

#[EventSubscriber(message: DiagnosticsAsyncWithoutBindingIdMessage::class, flow: 'async')]
final class DiagnosticsAsyncWithoutBindingIdHandler
{
    public function __invoke(DiagnosticsAsyncWithoutBindingIdMessage $message, MessageContextInterface $context): void
    {
    }
}

final class DiagnosticsDuplicateBindingEvent
{
}

#[EventSubscriber(message: DiagnosticsDuplicateBindingEvent::class, bindingId: 'diagnostics.duplicate_binding')]
final class DiagnosticsDuplicateBindingHandlerA
{
    public function __invoke(DiagnosticsDuplicateBindingEvent $message, MessageContextInterface $context): void
    {
    }
}

#[EventSubscriber(message: DiagnosticsDuplicateBindingEvent::class, bindingId: 'diagnostics.duplicate_binding')]
final class DiagnosticsDuplicateBindingHandlerB
{
    public function __invoke(DiagnosticsDuplicateBindingEvent $message, MessageContextInterface $context): void
    {
    }
}

final class DiagnosticsMissingFlowMessage
{
}

#[CommandHandler(message: DiagnosticsMissingFlowMessage::class, flow: 'missing')]
final class DiagnosticsMissingFlowHandler
{
    public function __invoke(DiagnosticsMissingFlowMessage $message, MessageContextInterface $context): void
    {
    }
}

final class DiagnosticsInvalidHandlerMessage
{
}

#[QueryHandler(message: DiagnosticsInvalidHandlerMessage::class, bindingId: 'diagnostics.invalid_handler')]
final class DiagnosticsInvalidHandler
{
    public function __invoke(DiagnosticsInvalidHandlerMessage $message): string
    {
        return 'invalid';
    }
}

final class DiagnosticsExpectedMessage
{
}

final class DiagnosticsWrongMessage
{
}

#[CommandHandler(message: DiagnosticsExpectedMessage::class)]
final class DiagnosticsMismatchedHandler
{
    public function __invoke(DiagnosticsWrongMessage $message, MessageContextInterface $context): void
    {
    }
}

final class DiagnosticsDuplicateQueryMessage
{
}

#[QueryHandler(message: DiagnosticsDuplicateQueryMessage::class)]
final class DiagnosticsDuplicateQueryHandlerA
{
    public function __invoke(DiagnosticsDuplicateQueryMessage $message, MessageContextInterface $context): string
    {
        return 'a';
    }
}

#[QueryHandler(message: DiagnosticsDuplicateQueryMessage::class)]
final class DiagnosticsDuplicateQueryHandlerB
{
    public function __invoke(DiagnosticsDuplicateQueryMessage $message, MessageContextInterface $context): string
    {
        return 'b';
    }
}

final class DiagnosticsPrimaryMissingCommand
{
}

#[CommandHandler(message: DiagnosticsPrimaryMissingCommand::class)]
final class DiagnosticsPrimaryMissingHandlerA
{
    public function __invoke(DiagnosticsPrimaryMissingCommand $message, MessageContextInterface $context): void
    {
    }
}

#[CommandHandler(message: DiagnosticsPrimaryMissingCommand::class)]
final class DiagnosticsPrimaryMissingHandlerB
{
    public function __invoke(DiagnosticsPrimaryMissingCommand $message, MessageContextInterface $context): void
    {
    }
}

final class DiagnosticsPrimaryDuplicateCommand
{
}

#[CommandHandler(message: DiagnosticsPrimaryDuplicateCommand::class, primary: true)]
final class DiagnosticsPrimaryDuplicateHandlerA
{
    public function __invoke(DiagnosticsPrimaryDuplicateCommand $message, MessageContextInterface $context): void
    {
    }
}

#[CommandHandler(message: DiagnosticsPrimaryDuplicateCommand::class, primary: true)]
final class DiagnosticsPrimaryDuplicateHandlerB
{
    public function __invoke(DiagnosticsPrimaryDuplicateCommand $message, MessageContextInterface $context): void
    {
    }
}

#[MessageAlias('diagnostics.async_query')]
final class DiagnosticsAsyncQueryMessage
{
}

#[QueryHandler(message: DiagnosticsAsyncQueryMessage::class, flow: 'async', bindingId: 'diagnostics.async_query')]
final class DiagnosticsAsyncQueryHandler
{
    public function __invoke(DiagnosticsAsyncQueryMessage $message, MessageContextInterface $context): string
    {
        return 'async';
    }
}

final class DiagnosticsMixedDispatchMessage
{
}

#[QueryHandler(message: DiagnosticsMixedDispatchMessage::class)]
final class DiagnosticsMixedDispatchQueryHandler
{
    public function __invoke(DiagnosticsMixedDispatchMessage $message, MessageContextInterface $context): string
    {
        return 'result';
    }
}

#[CommandHandler(message: DiagnosticsMixedDispatchMessage::class, primary: true)]
final class DiagnosticsMixedDispatchCommandHandler
{
    public function __invoke(DiagnosticsMixedDispatchMessage $message, MessageContextInterface $context): void
    {
    }
}

final class DiagnosticsInvalidInterceptorMessage
{
}

final class DiagnosticsInvalidInterceptor
{
    public function __invoke(string $context, PipelineInterface $pipeline): mixed
    {
        return $pipeline->continue();
    }
}

#[CommandHandler(message: DiagnosticsInvalidInterceptorMessage::class, middleware: [DiagnosticsInvalidInterceptor::class])]
final class DiagnosticsInvalidInterceptorHandler
{
    public function __invoke(DiagnosticsInvalidInterceptorMessage $message, MessageContextInterface $context): void
    {
    }
}

final class DiagnosticsNewInterceptorMessage
{
}

final class DiagnosticsNewInterceptor
{
    public function __invoke(MessageContextInterface $context, InterceptorPipelineInterface $pipeline): mixed
    {
        return $pipeline->continue();
    }
}

#[CommandHandler(message: DiagnosticsNewInterceptorMessage::class, middleware: [DiagnosticsNewInterceptor::class])]
final class DiagnosticsNewInterceptorHandler
{
    public function __invoke(DiagnosticsNewInterceptorMessage $message, MessageContextInterface $context): void
    {
    }
}

final class DiagnosticsProjectRuleMessage
{
}

#[CommandHandler(message: DiagnosticsProjectRuleMessage::class)]
final class DiagnosticsProjectRuleHandler
{
    public function __invoke(DiagnosticsProjectRuleMessage $message, MessageContextInterface $context): void
    {
    }
}

final class DiagnosticsWarningRule implements RegistryValidationRuleInterface
{
    public function validate(RegistryCompilationGraphContext $context): iterable
    {
        yield RegistryDiagnostic::warning('project.warning', 'Project validation warning.');
    }
}

final class DiagnosticsErrorRule implements RegistryValidationRuleInterface
{
    public function validate(RegistryCompilationGraphContext $context): iterable
    {
        yield RegistryDiagnostic::error('project.error', 'Project validation error.');
    }
}

final class DiagnosticsInvalidRuleResult implements RegistryValidationRuleInterface
{
    public function validate(RegistryCompilationGraphContext $context): iterable
    {
        yield new \stdClass();
    }
}

final class DiagnosticsFlowMessage
{
}

#[CommandHandler(message: DiagnosticsFlowMessage::class)]
final class DiagnosticsFlowHandler
{
    public function __invoke(DiagnosticsFlowMessage $message, MessageContextInterface $context): void
    {
    }
}

#[MessageAlias('diagnostics.flow.async')]
final class DiagnosticsFlowAsyncMessage
{
}

#[EventSubscriber(message: DiagnosticsFlowAsyncMessage::class, flow: 'async', bindingId: 'diagnostics.flow.async')]
final class DiagnosticsFlowAsyncHandler
{
    public function __invoke(DiagnosticsFlowAsyncMessage $message, MessageContextInterface $context): void
    {
    }
}

final class DiagnosticsNotAContext
{
}

final class DiagnosticsCustomContext implements MessageContextInterface
{
    public function envelope(): Envelope
    {
        throw new \RuntimeException('Not used by registry diagnostics tests.');
    }

    public function dispatch(object $message, PublishOptions $options = new PublishOptions()): mixed
    {
        throw new \RuntimeException('Not used by registry diagnostics tests.');
    }

    public function dispatchAll(object $message, PublishOptions $options = new PublishOptions()): HandlerExecutionResultInterface
    {
        throw new \RuntimeException('Not used by registry diagnostics tests.');
    }

    public function publish(object $message, PublishOptions $options = new PublishOptions()): PublishResult
    {
        throw new \RuntimeException('Not used by registry diagnostics tests.');
    }
}

final class DiagnosticsContextFactory implements MessageContextFactoryInterface
{
    public function create(
        MessageBusInterface $messageBus,
        Envelope $envelope,
        FlowDefinition $flow,
        ?WorkerRuntimeControlInterface $workerRuntimeControl = null,
    ): MessageContextInterface {
        return new DiagnosticsCustomContext();
    }
}

final class DiagnosticsNoArgumentsMessage
{
}

#[CommandHandler(message: DiagnosticsNoArgumentsMessage::class)]
final class DiagnosticsNoArgumentsHandler
{
    public function __invoke(): void
    {
    }
}

final class DiagnosticsContextlessMessage
{
}

#[CommandHandler(message: DiagnosticsContextlessMessage::class, contextAware: false)]
final class DiagnosticsContextlessWithContextHandler
{
    public function __invoke(DiagnosticsContextlessMessage $message, MessageContextInterface $context): void
    {
    }
}

final class DiagnosticsQueryWithoutReturnMessage
{
}

#[QueryHandler(message: DiagnosticsQueryWithoutReturnMessage::class)]
final class DiagnosticsQueryWithoutReturnHandler
{
    public function __invoke(DiagnosticsQueryWithoutReturnMessage $message, MessageContextInterface $context): void
    {
    }
}

final class DiagnosticsCommandWithReturnMessage
{
}

#[CommandHandler(message: DiagnosticsCommandWithReturnMessage::class)]
final class DiagnosticsCommandWithReturnHandler
{
    public function __invoke(DiagnosticsCommandWithReturnMessage $message, MessageContextInterface $context): string
    {
        return 'invalid';
    }
}

final class DiagnosticsEventWithReturnMessage
{
}

#[EventSubscriber(message: DiagnosticsEventWithReturnMessage::class)]
final class DiagnosticsEventWithReturnHandler
{
    public function __invoke(DiagnosticsEventWithReturnMessage $message, MessageContextInterface $context): string
    {
        return 'invalid';
    }
}

final class DiagnosticsMissingInterceptorMessage
{
}

final class DiagnosticsMissingInterceptor
{
    public function handle(): void
    {
    }
}

#[CommandHandler(message: DiagnosticsMissingInterceptorMessage::class, middleware: [DiagnosticsMissingInterceptor::class])]
final class DiagnosticsMissingInterceptorHandler
{
    public function __invoke(DiagnosticsMissingInterceptorMessage $message, MessageContextInterface $context): void
    {
    }
}

final class DiagnosticsTooFewInterceptorMessage
{
}

final class DiagnosticsTooFewInterceptor
{
    public function __invoke(MessageContextInterface $context): mixed
    {
        return null;
    }
}

#[CommandHandler(message: DiagnosticsTooFewInterceptorMessage::class, middleware: [DiagnosticsTooFewInterceptor::class])]
final class DiagnosticsTooFewInterceptorHandler
{
    public function __invoke(DiagnosticsTooFewInterceptorMessage $message, MessageContextInterface $context): void
    {
    }
}

final class DiagnosticsWrongPipelineInterceptorMessage
{
}

final class DiagnosticsWrongPipelineInterceptor
{
    public function __invoke(MessageContextInterface $context, string $pipeline): mixed
    {
        return null;
    }
}

#[CommandHandler(message: DiagnosticsWrongPipelineInterceptorMessage::class, middleware: [DiagnosticsWrongPipelineInterceptor::class])]
final class DiagnosticsWrongPipelineInterceptorHandler
{
    public function __invoke(DiagnosticsWrongPipelineInterceptorMessage $message, MessageContextInterface $context): void
    {
    }
}
