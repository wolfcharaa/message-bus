<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Tests;

use PHPUnit\Framework\TestCase;
use Wolfcharaa\MessageBus\Attribute\MessageAlias;
use Wolfcharaa\MessageBus\Attribute\QueryHandler;
use Wolfcharaa\MessageBus\Context\MessageContextInterface;
use Wolfcharaa\MessageBus\Discovery\ClassListProvider;
use Wolfcharaa\MessageBus\Dumper\CompiledRegistryFileWriter;
use Wolfcharaa\MessageBus\Flow\FlowDefinition;
use Wolfcharaa\MessageBus\Flow\FlowRegistry;
use Wolfcharaa\MessageBus\MessageBus;
use Wolfcharaa\MessageBus\Registry\CompiledMessageRegistry;
use Wolfcharaa\MessageBus\Registry\HandlerBindingDefinition;
use Wolfcharaa\MessageBus\Registry\HandlerInvocationMode;
use Wolfcharaa\MessageBus\Registry\MessageRegistryCompiler;
use Wolfcharaa\MessageBus\Registry\RegistryDiagnostic;
use Wolfcharaa\MessageBus\Registry\RegistryDiagnosticCodes;
use Wolfcharaa\MessageBus\Tests\Support\TestContainer;

final class ContextlessHandlerTest extends TestCase
{
    public function testContextlessQueryUsesExplicitInvocationMode(): void
    {
        $result = (new MessageRegistryCompiler())->compileWithDiagnostics(new ClassListProvider([
            ContextlessLookupMessage::class,
            ContextlessLookupHandler::class,
        ]));

        self::assertTrue($result->hasDefinition(), self::diagnosticsAsString($result->diagnostics));
        self::assertNotNull($result->definition);

        $registry = new CompiledMessageRegistry($result->definition);
        $bindings = $registry->bindingsForMessage(ContextlessLookupMessage::class);

        self::assertCount(1, $bindings);
        self::assertSame('default', $bindings[0]->flow);
        self::assertSame(HandlerInvocationMode::Contextless, $bindings[0]->invocationMode);
        self::assertSame('contextless', $bindings[0]->toArray()['invocationMode']);

        $bus = new MessageBus($registry, $result->definition->flows, new TestContainer());

        self::assertSame('contextless:77', $bus->dispatch(new ContextlessLookupMessage(77)));
    }

    public function testSerializedBindingRequiresInvocationMetadata(): void
    {
        $data = HandlerBindingDefinition::query(
            ContextlessLegacyQueryMessage::class,
            ContextlessLegacyQueryHandler::class,
            '__invoke',
            'default',
            0,
            'legacy.query',
        )->toArray();

        $binding = HandlerBindingDefinition::fromArray($data);

        self::assertSame(HandlerInvocationMode::ContextAware, $binding->invocationMode);
    }

    public function testCompiledRegistryPreservesContextlessInvocationMetadata(): void
    {
        $result = (new MessageRegistryCompiler())->compileWithDiagnostics(new ClassListProvider([
            ContextlessLookupMessage::class,
            ContextlessLookupHandler::class,
        ]));
        self::assertNotNull($result->definition);

        $file = \tempnam(\sys_get_temp_dir(), 'message-bus-contextless-registry-');
        self::assertIsString($file);

        try {
            (new CompiledRegistryFileWriter())->write($result->definition, $file);
            $registry = CompiledMessageRegistry::fromFile($file);
            $binding = $registry->bindingsForMessage(ContextlessLookupMessage::class)[0];

            self::assertSame(HandlerInvocationMode::Contextless, $binding->invocationMode);

            $bus = new MessageBus($registry, $registry->definition()->flows, new TestContainer());
            self::assertSame('contextless:91', $bus->dispatch(new ContextlessLookupMessage(91)));
        } finally {
            @\unlink($file);
        }
    }

    public function testContextlessAttributeCanDeclareMultipleBindings(): void
    {
        $result = (new MessageRegistryCompiler())->compileWithDiagnostics(new ClassListProvider([
            ContextlessRepeatedFirstMessage::class,
            ContextlessRepeatedSecondMessage::class,
            ContextlessRepeatedHandler::class,
        ]));

        self::assertTrue($result->hasDefinition(), self::diagnosticsAsString($result->diagnostics));
        self::assertArrayHasKey('contextless.repeated.first', $result->definition?->bindings);
        self::assertArrayHasKey('contextless.repeated.second', $result->definition?->bindings);
    }

    public function testContextlessHandlerCanUseExplicitCustomSyncFlow(): void
    {
        $result = (new MessageRegistryCompiler())->compileWithDiagnostics(
            new ClassListProvider([
                ContextlessCustomFlowMessage::class,
                ContextlessCustomFlowHandler::class,
            ]),
            new FlowRegistry(FlowDefinition::sync('domain_read')),
        );

        self::assertTrue($result->hasDefinition(), self::diagnosticsAsString($result->diagnostics));
        self::assertSame('domain_read', $result->definition?->bindings['contextless.custom_flow']->flow);
    }

    public function testContextlessHandlerRejectsContextAwareSignature(): void
    {
        $result = (new MessageRegistryCompiler())->compileWithDiagnostics(new ClassListProvider([
            ContextlessContextMessage::class,
            ContextlessContextAwareHandler::class,
        ]));

        self::assertFalse($result->hasDefinition());
        self::assertDiagnosticCode($result->diagnostics, RegistryDiagnosticCodes::HANDLER_INVALID_SIGNATURE);
        self::assertStringContainsString('must not accept MessageContextInterface', self::diagnosticsAsString($result->diagnostics));
    }

    public function testContextlessQueryRejectsAsyncFlow(): void
    {
        $result = (new MessageRegistryCompiler())->compileWithDiagnostics(
            new ClassListProvider([
                ContextlessAsyncMessage::class,
                ContextlessAsyncHandler::class,
            ]),
            new FlowRegistry(FlowDefinition::async('async')->transport('postgres', 'contextless')),
        );

        self::assertFalse($result->hasDefinition());
        self::assertDiagnosticCode($result->diagnostics, RegistryDiagnosticCodes::QUERY_ASYNC_FLOW);
    }

    public function testRegularQueryHandlerStillRequiresContextAwareSignature(): void
    {
        $result = (new MessageRegistryCompiler())->compileWithDiagnostics(new ClassListProvider([
            ContextlessLegacyQueryMessage::class,
            ContextlessLegacyQueryHandler::class,
        ]));

        self::assertFalse($result->hasDefinition());
        self::assertDiagnosticCode($result->diagnostics, RegistryDiagnosticCodes::HANDLER_INVALID_SIGNATURE);
        self::assertStringContainsString('must accept message and context arguments', self::diagnosticsAsString($result->diagnostics));
    }

    /** @param list<RegistryDiagnostic> $diagnostics */
    private static function assertDiagnosticCode(array $diagnostics, string $code): void
    {
        foreach ($diagnostics as $diagnostic) {
            if ($diagnostic->code === $code) {
                return;
            }
        }

        self::fail('Diagnostic code not found: ' . $code . "\n" . self::diagnosticsAsString($diagnostics));
    }

    /** @param list<RegistryDiagnostic> $diagnostics */
    private static function diagnosticsAsString(array $diagnostics): string
    {
        return \implode("\n", \array_map(
            static fn (RegistryDiagnostic $diagnostic): string => $diagnostic->severity->value . ' ' . $diagnostic->code . ': ' . $diagnostic->message,
            $diagnostics,
        ));
    }
}

final readonly class ContextlessLookupMessage
{
    public function __construct(public int $id)
    {
    }
}

#[QueryHandler(message: ContextlessLookupMessage::class, contextAware: false)]
final class ContextlessLookupHandler
{
    public function __invoke(ContextlessLookupMessage $message): string
    {
        return 'contextless:' . $message->id;
    }
}

final class ContextlessContextMessage
{
}

#[QueryHandler(message: ContextlessContextMessage::class, contextAware: false)]
final class ContextlessContextAwareHandler
{
    public function __invoke(ContextlessContextMessage $message, MessageContextInterface $context): string
    {
        return 'invalid';
    }
}

#[MessageAlias('contextless.async')]
final class ContextlessAsyncMessage
{
}

#[QueryHandler(message: ContextlessAsyncMessage::class, flow: 'async', bindingId: 'contextless.async', contextAware: false)]
final class ContextlessAsyncHandler
{
    public function __invoke(ContextlessAsyncMessage $message): string
    {
        return 'invalid';
    }
}

final class ContextlessLegacyQueryMessage
{
}

#[QueryHandler(message: ContextlessLegacyQueryMessage::class)]
final class ContextlessLegacyQueryHandler
{
    public function __invoke(ContextlessLegacyQueryMessage $message): string
    {
        return 'invalid';
    }
}

final class ContextlessCustomFlowMessage
{
}

#[QueryHandler(message: ContextlessCustomFlowMessage::class, flow: 'domain_read', bindingId: 'contextless.custom_flow', contextAware: false)]
final class ContextlessCustomFlowHandler
{
    public function __invoke(ContextlessCustomFlowMessage $message): string
    {
        return 'custom';
    }
}

final class ContextlessRepeatedFirstMessage
{
}

final class ContextlessRepeatedSecondMessage
{
}

#[QueryHandler(
    message: ContextlessRepeatedFirstMessage::class,
    method: 'first',
    bindingId: 'contextless.repeated.first',
    contextAware: false,
)]
#[QueryHandler(
    message: ContextlessRepeatedSecondMessage::class,
    method: 'second',
    bindingId: 'contextless.repeated.second',
    contextAware: false,
)]
final class ContextlessRepeatedHandler
{
    public function first(ContextlessRepeatedFirstMessage $message): string
    {
        return 'first';
    }

    public function second(ContextlessRepeatedSecondMessage $message): string
    {
        return 'second';
    }
}
