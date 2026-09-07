<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Tests;

use PHPUnit\Framework\TestCase;
use Wolfcharaa\MessageBus\Attribute\CacheResult;
use Wolfcharaa\MessageBus\Attribute\CommandHandler;
use Wolfcharaa\MessageBus\Attribute\EventSubscriber;
use Wolfcharaa\MessageBus\Attribute\MessageAlias;
use Wolfcharaa\MessageBus\Attribute\QueryHandler;
use Wolfcharaa\MessageBus\Context\MessageContextInterface;
use Wolfcharaa\MessageBus\Discovery\AttributeHandlerDiscovery;
use Wolfcharaa\MessageBus\Discovery\ClassListProvider;
use Wolfcharaa\MessageBus\Flow\FlowDefinition;
use Wolfcharaa\MessageBus\Flow\FlowRegistry;
use Wolfcharaa\MessageBus\Interceptor\PipelineInterface as InterceptorPipelineInterface;
use Wolfcharaa\MessageBus\Middleware\PipelineInterface;
use Wolfcharaa\MessageBus\Registry\DeprecationDiagnosticsMode;
use Wolfcharaa\MessageBus\Registry\MessageRegistryCompiler;
use Wolfcharaa\MessageBus\Registry\MessageRegistryCompilerOptions;
use Wolfcharaa\MessageBus\Registry\RegistryCompilationException;
use Wolfcharaa\MessageBus\Registry\RegistryCompilationGraphContext;
use Wolfcharaa\MessageBus\Registry\RegistryCompilationStage;
use Wolfcharaa\MessageBus\Registry\RegistryDiagnostic;
use Wolfcharaa\MessageBus\Registry\RegistryDiagnosticCodes;
use Wolfcharaa\MessageBus\Registry\RegistryDiagnosticSeverity;
use Wolfcharaa\MessageBus\Registry\RegistryValidationRuleInterface;

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
            options: new MessageRegistryCompilerOptions(deprecations: DeprecationDiagnosticsMode::Fail),
        );

        self::assertTrue($result->hasDefinition());
        self::assertFalse($result->hasErrors());
    }

    public function testLegacyMiddlewareDeprecationModeControlsDiagnosticsAndFailure(): void
    {
        $provider = new ClassListProvider([
            DiagnosticsLegacyInterceptorMessage::class,
            DiagnosticsLegacyInterceptorHandler::class,
        ]);
        $compiler = new MessageRegistryCompiler();

        $ignored = $compiler->compileWithDiagnostics($provider);
        self::assertTrue($ignored->hasDefinition());
        self::assertNull(self::diagnostic($ignored->diagnostics, RegistryDiagnosticCodes::INTERCEPTOR_LEGACY_MIDDLEWARE));

        $warned = $compiler->compileWithDiagnostics(
            $provider,
            options: new MessageRegistryCompilerOptions(deprecations: DeprecationDiagnosticsMode::Warn),
        );
        self::assertTrue($warned->hasDefinition());
        self::assertSame(
            RegistryDiagnosticSeverity::Warning,
            self::diagnostic($warned->diagnostics, RegistryDiagnosticCodes::INTERCEPTOR_LEGACY_MIDDLEWARE)?->severity,
        );

        $failed = $compiler->compileWithDiagnostics(
            $provider,
            options: new MessageRegistryCompilerOptions(deprecations: DeprecationDiagnosticsMode::Fail),
        );
        self::assertFalse($failed->hasDefinition());
        self::assertSame(
            RegistryDiagnosticSeverity::Error,
            self::diagnostic($failed->diagnostics, RegistryDiagnosticCodes::INTERCEPTOR_LEGACY_MIDDLEWARE)?->severity,
        );
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
#[CommandHandler(message: DiagnosticsCacheMessageA::class, method: 'handleA')]
#[CommandHandler(message: DiagnosticsCacheMessageB::class, method: 'handleB')]
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
    public function __invoke(DiagnosticsMissingFlowMessage $message, MessageContextInterface $context): string
    {
        return 'ok';
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
    public function __invoke(DiagnosticsInvalidInterceptorMessage $message, MessageContextInterface $context): string
    {
        return 'ok';
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
    public function __invoke(DiagnosticsNewInterceptorMessage $message, MessageContextInterface $context): string
    {
        return 'ok';
    }
}

final class DiagnosticsLegacyInterceptorMessage
{
}

final class DiagnosticsLegacyInterceptor
{
    public function __invoke(MessageContextInterface $context, PipelineInterface $pipeline): mixed
    {
        return $pipeline->continue();
    }
}

#[CommandHandler(message: DiagnosticsLegacyInterceptorMessage::class, middleware: [DiagnosticsLegacyInterceptor::class])]
final class DiagnosticsLegacyInterceptorHandler
{
    public function __invoke(DiagnosticsLegacyInterceptorMessage $message, MessageContextInterface $context): string
    {
        return 'ok';
    }
}

final class DiagnosticsProjectRuleMessage
{
}

#[CommandHandler(message: DiagnosticsProjectRuleMessage::class)]
final class DiagnosticsProjectRuleHandler
{
    public function __invoke(DiagnosticsProjectRuleMessage $message, MessageContextInterface $context): string
    {
        return 'ok';
    }
}

final class DiagnosticsWarningRule implements RegistryValidationRuleInterface
{
    public function validate(RegistryCompilationGraphContext $context): iterable
    {
        yield RegistryDiagnostic::warning('project.warning', 'Project validation warning.');
    }
}
