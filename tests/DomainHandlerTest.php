<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Tests;

use PHPUnit\Framework\TestCase;
use Wolfcharaa\MessageBus\Attribute\DomainHandler;
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
use Wolfcharaa\MessageBus\Registry\HandlerRole;
use Wolfcharaa\MessageBus\Registry\MessageRegistryCompiler;
use Wolfcharaa\MessageBus\Registry\RegistryDiagnostic;
use Wolfcharaa\MessageBus\Registry\RegistryDiagnosticCodes;
use Wolfcharaa\MessageBus\Tests\Support\TestContainer;

final class DomainHandlerTest extends TestCase
{
    public function testDomainHandlerUsesContextlessInvocationAndDefaultDomainFlow(): void
    {
        $result = (new MessageRegistryCompiler())->compileWithDiagnostics(new ClassListProvider([
            DomainHandlerLookupMessage::class,
            DomainHandlerLookupHandler::class,
        ]));

        self::assertTrue($result->hasDefinition(), self::diagnosticsAsString($result->diagnostics));
        self::assertNotNull($result->definition);

        $registry = new CompiledMessageRegistry($result->definition);
        $bindings = $registry->bindingsForMessage(DomainHandlerLookupMessage::class);

        self::assertCount(1, $bindings);
        self::assertSame('domain_capability', $bindings[0]->flow);
        self::assertSame(HandlerRole::Domain, $bindings[0]->role);
        self::assertSame(HandlerInvocationMode::Contextless, $bindings[0]->invocationMode);
        self::assertSame('domain', $bindings[0]->toArray()['role']);
        self::assertSame('contextless', $bindings[0]->toArray()['invocationMode']);
        self::assertTrue($result->definition->flows->get('domain_capability')->isSync());

        $bus = new MessageBus($registry, $result->definition->flows, new TestContainer());

        self::assertSame('domain:77', $bus->dispatch(new DomainHandlerLookupMessage(77)));
    }

    public function testLegacySerializedBindingDefaultsToApplicationContextAwareMode(): void
    {
        $data = HandlerBindingDefinition::query(
            DomainHandlerLegacyQueryMessage::class,
            DomainHandlerLegacyQueryHandler::class,
            '__invoke',
            'default',
            0,
            'legacy.query',
        )->toArray();
        unset($data['role'], $data['invocationMode']);

        $binding = HandlerBindingDefinition::fromArray($data);

        self::assertSame(HandlerRole::Application, $binding->role);
        self::assertSame(HandlerInvocationMode::ContextAware, $binding->invocationMode);
    }

    public function testCompiledRegistryPreservesContextlessInvocationMetadata(): void
    {
        $result = (new MessageRegistryCompiler())->compileWithDiagnostics(new ClassListProvider([
            DomainHandlerLookupMessage::class,
            DomainHandlerLookupHandler::class,
        ]));
        self::assertNotNull($result->definition);

        $file = \tempnam(\sys_get_temp_dir(), 'message-bus-domain-registry-');
        self::assertIsString($file);

        try {
            (new CompiledRegistryFileWriter())->write($result->definition, $file);
            $registry = CompiledMessageRegistry::fromFile($file);
            $binding = $registry->bindingsForMessage(DomainHandlerLookupMessage::class)[0];

            self::assertSame(HandlerRole::Domain, $binding->role);
            self::assertSame(HandlerInvocationMode::Contextless, $binding->invocationMode);

            $bus = new MessageBus($registry, $registry->definition()->flows, new TestContainer());
            self::assertSame('domain:91', $bus->dispatch(new DomainHandlerLookupMessage(91)));
        } finally {
            @\unlink($file);
        }
    }

    public function testDomainHandlerAttributeCanDeclareMultipleDomainBindings(): void
    {
        $result = (new MessageRegistryCompiler())->compileWithDiagnostics(new ClassListProvider([
            DomainHandlerRepeatedFirstMessage::class,
            DomainHandlerRepeatedSecondMessage::class,
            DomainHandlerRepeatedHandler::class,
        ]));

        self::assertTrue($result->hasDefinition(), self::diagnosticsAsString($result->diagnostics));
        self::assertArrayHasKey('domain.repeated.first', $result->definition?->bindings);
        self::assertArrayHasKey('domain.repeated.second', $result->definition?->bindings);
    }

    public function testDomainHandlerCanUseExplicitCustomSyncFlow(): void
    {
        $result = (new MessageRegistryCompiler())->compileWithDiagnostics(
            new ClassListProvider([
                DomainHandlerCustomFlowMessage::class,
                DomainHandlerCustomFlowHandler::class,
            ]),
            new FlowRegistry(FlowDefinition::sync('domain_read')),
        );

        self::assertTrue($result->hasDefinition(), self::diagnosticsAsString($result->diagnostics));
        self::assertSame('domain_read', $result->definition?->bindings['domain.custom_flow']->flow);
    }

    public function testDomainHandlerRejectsContextAwareSignature(): void
    {
        $result = (new MessageRegistryCompiler())->compileWithDiagnostics(new ClassListProvider([
            DomainHandlerContextMessage::class,
            DomainHandlerContextAwareHandler::class,
        ]));

        self::assertFalse($result->hasDefinition());
        self::assertDiagnosticCode($result->diagnostics, RegistryDiagnosticCodes::HANDLER_INVALID_SIGNATURE);
        self::assertStringContainsString('must not accept MessageContextInterface', self::diagnosticsAsString($result->diagnostics));
    }

    public function testDomainHandlerRejectsAsyncFlow(): void
    {
        $result = (new MessageRegistryCompiler())->compileWithDiagnostics(
            new ClassListProvider([
                DomainHandlerAsyncMessage::class,
                DomainHandlerAsyncHandler::class,
            ]),
            new FlowRegistry(FlowDefinition::async('async')->transport('postgres', 'domain')),
        );

        self::assertFalse($result->hasDefinition());
        self::assertDiagnosticCode($result->diagnostics, RegistryDiagnosticCodes::DOMAIN_HANDLER_ASYNC_FLOW);
    }

    public function testRegularQueryHandlerStillRequiresContextAwareSignature(): void
    {
        $result = (new MessageRegistryCompiler())->compileWithDiagnostics(new ClassListProvider([
            DomainHandlerLegacyQueryMessage::class,
            DomainHandlerLegacyQueryHandler::class,
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

final readonly class DomainHandlerLookupMessage
{
    public function __construct(public int $id)
    {
    }
}

#[DomainHandler(message: DomainHandlerLookupMessage::class)]
final class DomainHandlerLookupHandler
{
    public function __invoke(DomainHandlerLookupMessage $message): string
    {
        return 'domain:' . $message->id;
    }
}

final class DomainHandlerContextMessage
{
}

#[DomainHandler(message: DomainHandlerContextMessage::class)]
final class DomainHandlerContextAwareHandler
{
    public function __invoke(DomainHandlerContextMessage $message, MessageContextInterface $context): string
    {
        return 'invalid';
    }
}

final class DomainHandlerAsyncMessage
{
}

#[DomainHandler(message: DomainHandlerAsyncMessage::class, flow: 'async', bindingId: 'domain.async')]
final class DomainHandlerAsyncHandler
{
    public function __invoke(DomainHandlerAsyncMessage $message): string
    {
        return 'invalid';
    }
}

final class DomainHandlerLegacyQueryMessage
{
}

#[QueryHandler(message: DomainHandlerLegacyQueryMessage::class)]
final class DomainHandlerLegacyQueryHandler
{
    public function __invoke(DomainHandlerLegacyQueryMessage $message): string
    {
        return 'invalid';
    }
}

final class DomainHandlerCustomFlowMessage
{
}

#[DomainHandler(message: DomainHandlerCustomFlowMessage::class, flow: 'domain_read', bindingId: 'domain.custom_flow')]
final class DomainHandlerCustomFlowHandler
{
    public function __invoke(DomainHandlerCustomFlowMessage $message): string
    {
        return 'custom';
    }
}

final class DomainHandlerRepeatedFirstMessage
{
}

final class DomainHandlerRepeatedSecondMessage
{
}

#[DomainHandler(
    message: DomainHandlerRepeatedFirstMessage::class,
    method: 'first',
    bindingId: 'domain.repeated.first',
)]
#[DomainHandler(
    message: DomainHandlerRepeatedSecondMessage::class,
    method: 'second',
    bindingId: 'domain.repeated.second',
)]
final class DomainHandlerRepeatedHandler
{
    public function first(DomainHandlerRepeatedFirstMessage $message): string
    {
        return 'first';
    }

    public function second(DomainHandlerRepeatedSecondMessage $message): string
    {
        return 'second';
    }
}
