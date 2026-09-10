<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Tests;

use BackedEnum;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use RuntimeException;
use Wolfcharaa\MessageBus\Cache\JsonResultSerializer;
use Wolfcharaa\MessageBus\Cache\PhpSerializeResultSerializer;
use Wolfcharaa\MessageBus\Cache\SerializedResult;
use Wolfcharaa\MessageBus\Cli\BootstrapResolver;
use Wolfcharaa\MessageBus\Context\MessageContextInterface;
use Wolfcharaa\MessageBus\Envelope\Envelope;
use Wolfcharaa\MessageBus\Exception\ContainerServiceInvalid;
use Wolfcharaa\MessageBus\Exception\ContainerServiceNotFound;
use Wolfcharaa\MessageBus\Execution\HandlerExecutionResultInterface;
use Wolfcharaa\MessageBus\Flow\TransportDefinition;
use Wolfcharaa\MessageBus\Interceptor\Pipeline;
use Wolfcharaa\MessageBus\Interceptor\PipelineInterface;
use Wolfcharaa\MessageBus\Invoker\ReflectionCallableInvoker;
use Wolfcharaa\MessageBus\MessageBatchItem;
use Wolfcharaa\MessageBus\MessageBusInterface;
use Wolfcharaa\MessageBus\PublishOptions;
use Wolfcharaa\MessageBus\PublishResult;
use Wolfcharaa\MessageBus\Envelope\SerializedEnvelope;
use Wolfcharaa\MessageBus\Queue\ConsumerOptions;
use Wolfcharaa\MessageBus\Queue\MessageConsumerInterface;
use Wolfcharaa\MessageBus\Queue\Postgres\PostgresMessageConsumer;
use Wolfcharaa\MessageBus\Queue\Postgres\PostgresQueueProvider;
use Wolfcharaa\MessageBus\Queue\Postgres\PostgresQueueStorageInterface;
use Wolfcharaa\MessageBus\Queue\QueueBatchEnqueueResult;
use Wolfcharaa\MessageBus\Queue\QueueEnqueueResult;
use Wolfcharaa\MessageBus\Queue\QueueJobState;
use Wolfcharaa\MessageBus\Queue\QueueJobStatus;
use Wolfcharaa\MessageBus\Queue\QueueMessage;
use Wolfcharaa\MessageBus\Queue\QueueWorkerInterface;
use Wolfcharaa\MessageBus\Queue\QueueWorkerRunner;
use Wolfcharaa\MessageBus\Queue\ReceivedQueueMessage;
use Wolfcharaa\MessageBus\Registry\HandlerBindingDefinition;
use Wolfcharaa\MessageBus\Registry\HandlerInvocationMode;
use Wolfcharaa\MessageBus\Registry\RegistryCompilationException;
use Wolfcharaa\MessageBus\Registry\RegistryDiagnostic;
use Wolfcharaa\MessageBus\Registry\RegistryDiagnosticOrigin;
use Wolfcharaa\MessageBus\Runtime\MessageBusRuntime;
use Wolfcharaa\MessageBus\Serialization\SerializedMessage;
use Wolfcharaa\MessageBus\Tests\Support\TestContainer;

final class InfrastructureContractsTest extends TestCase
{
    public function testBootstrapResolverUsesEnvironmentAndConventionFallbacks(): void
    {
        $runtimeBootstrap = $this->bootstrap('return \\' . InfrastructureBootstrapFactory::class . '::runtime();');
        $runnerBootstrap = $this->bootstrap('return \\' . InfrastructureBootstrapFactory::class . '::runner();');
        $previous = \getenv('MESSAGE_BUS_BOOTSTRAP');
        \putenv('MESSAGE_BUS_BOOTSTRAP=' . $runtimeBootstrap);

        try {
            $runtime = (new BootstrapResolver([]))->resolve();
            self::assertInstanceOf(MessageBusRuntime::class, $runtime);
            self::assertInstanceOf(InfrastructureBus::class, $runtime->bus());

            \putenv('MESSAGE_BUS_BOOTSTRAP');
            $runner = (new BootstrapResolver([$runnerBootstrap]))->resolve();
            self::assertInstanceOf(QueueWorkerRunner::class, $runner);
        } finally {
            if ($previous === false) {
                \putenv('MESSAGE_BUS_BOOTSTRAP');
            } else {
                \putenv('MESSAGE_BUS_BOOTSTRAP=' . $previous);
            }

            @\unlink($runtimeBootstrap);
            @\unlink($runnerBootstrap);
        }
    }

    public function testReflectionCallableInvokerInvokesObjectAndContainerServices(): void
    {
        $service = new InfrastructureCallableService();
        $invoker = new ReflectionCallableInvoker(new TestContainer([
            InfrastructureCallableService::class => $service,
        ], autowireClasses: false));

        self::assertSame('direct:42', $invoker->invoke($service, 'handle', ['direct', 42]));
        self::assertSame('container:7', $invoker->invoke(InfrastructureCallableService::class, 'handle', ['container', 7]));
    }

    public function testReflectionCallableInvokerReportsInvalidTargets(): void
    {
        $missing = new ReflectionCallableInvoker(new TestContainer([], autowireClasses: false));
        try {
            $missing->invoke(InfrastructureCallableService::class, 'handle', ['missing', 1]);
            self::fail('Expected missing callable service.');
        } catch (ContainerServiceNotFound $e) {
            self::assertStringContainsString('callable target', $e->getMessage());
        }

        $invalid = new ReflectionCallableInvoker(new TestContainer([
            InfrastructureCallableService::class => 'not-object',
        ], autowireClasses: false));
        try {
            $invalid->invoke(InfrastructureCallableService::class, 'handle', ['invalid', 1]);
            self::fail('Expected invalid callable service.');
        } catch (ContainerServiceInvalid $e) {
            self::assertStringContainsString('Expected `object`, got `string`', $e->getMessage());
        }

        $throwing = new ReflectionCallableInvoker(new InfrastructureThrowingContainer());
        try {
            $throwing->invoke(InfrastructureCallableService::class, 'handle', ['broken', 1]);
            self::fail('Expected container exception to be wrapped.');
        } catch (ContainerServiceInvalid $e) {
            self::assertStringContainsString('container error', $e->getMessage());
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Callable target `' . InfrastructureCallableService::class . '::missing` does not exist.');

        $missing->invoke(new InfrastructureCallableService(), 'missing', []);
    }

    public function testPipelineRunsInterceptorsAndContextlessHandlerInOrder(): void
    {
        InfrastructurePipelineLog::$events = [];
        $message = new InfrastructurePipelineMessage('payload');
        $context = new InfrastructureMessageContext($message);
        $binding = HandlerBindingDefinition::command(
            InfrastructurePipelineMessage::class,
            InfrastructurePipelineAction::class,
            'handle',
            'default',
            true,
            0,
            'pipeline.binding',
            [InfrastructureFirstInterceptor::class, InfrastructureSecondInterceptor::class],
            invocationMode: HandlerInvocationMode::Contextless,
        );
        $invoker = new ReflectionCallableInvoker(new TestContainer([
            InfrastructurePipelineAction::class => new InfrastructurePipelineAction(),
            InfrastructureFirstInterceptor::class => new InfrastructureFirstInterceptor(),
            InfrastructureSecondInterceptor::class => new InfrastructureSecondInterceptor(),
        ], autowireClasses: false));

        $result = (new Pipeline($binding, $context, $invoker, $binding->middleware))->continue();

        self::assertSame('handled:payload', $result);
        self::assertSame([
            'first.before',
            'second.before',
            'handler',
            'second.after',
            'first.after',
        ], InfrastructurePipelineLog::$events);
    }

    public function testTransportDefinitionRoundTripSupportsBackedEnumsAndNull(): void
    {
        $transport = TransportDefinition::from(InfrastructureTransport::Postgres, InfrastructureQueue::Critical);

        self::assertSame('postgres', $transport->transport);
        self::assertSame('critical', $transport->queue);
        self::assertEquals($transport, TransportDefinition::fromArray($transport->toArray()));
        self::assertNull(TransportDefinition::fromArray(null));
    }

    public function testJsonResultSerializerHandlesPortableResultsAndRejectsInvalidPayloads(): void
    {
        $serializer = new JsonResultSerializer();
        $dto = new InfrastructureJsonResultDto('ok', ['region' => '77']);

        $serializedDto = $serializer->serialize($dto);

        self::assertSame('application/json', $serializedDto->contentType);
        self::assertSame(InfrastructureJsonResultDto::class, $serializedDto->className);
        self::assertEquals($dto, $serializer->deserialize($serializedDto));
        self::assertSame(['value' => 'ok'], $serializer->deserialize($serializer->serialize(['value' => 'ok'])));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Only application/json cached results are supported.');

        $serializer->deserialize(new SerializedResult('application/xml', '<value />'));
    }

    public function testJsonResultSerializerRejectsNonPortableObjectGraph(): void
    {
        $serializer = new JsonResultSerializer();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cached result supports only scalar, array, object DTO and null values.');

        $serializer->serialize(new InfrastructureNonPortableResult(new \stdClass()));
    }

    public function testPhpSerializeResultSerializerRejectsInvalidPayloadsAndUnexpectedClasses(): void
    {
        $serializer = new PhpSerializeResultSerializer();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Only application/vnd.php.serialized cached results are supported.');

        $serializer->deserialize(new SerializedResult('application/json', '{}'));
    }

    public function testPhpSerializeResultSerializerRejectsMalformedPayload(): void
    {
        $serializer = new PhpSerializeResultSerializer();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('PHP serialized cached result payload is invalid.');

        $serializer->deserialize(new SerializedResult(PhpSerializeResultSerializer::CONTENT_TYPE, 'not serialized'));
    }

    public function testPhpSerializeResultSerializerRejectsUnexpectedClassName(): void
    {
        $serializer = new PhpSerializeResultSerializer();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must contain instance of `' . InfrastructureJsonResultDto::class . '`');

        $serializer->deserialize(new SerializedResult(
            PhpSerializeResultSerializer::CONTENT_TYPE,
            \serialize(new InfrastructureOtherResultDto()),
            InfrastructureJsonResultDto::class,
        ));
    }

    public function testRegistryCompilationExceptionSummarizesDiagnostics(): void
    {
        $warning = RegistryDiagnostic::warning(
            'project.warning',
            'Project warning.',
            RegistryDiagnosticOrigin::compiler('project-rule', 'project.rule'),
        );
        $error = RegistryDiagnostic::error(
            'project.error',
            'Project error.',
            RegistryDiagnosticOrigin::flow('default', 'flow.rule'),
        );

        $exception = RegistryCompilationException::fromDiagnostics([$warning, $error], 'Custom failure');

        self::assertSame([$warning, $error], $exception->diagnostics());
        self::assertTrue($exception->hasErrors());
        self::assertTrue($exception->hasWarnings());
        self::assertStringContainsString('Custom failure: 2 diagnostic(s), first error project.error: Project error.', $exception->getMessage());
        self::assertStringContainsString('at default', $exception->getMessage());
        self::assertSame('Fallback only', RegistryCompilationException::fromDiagnostics([], 'Fallback only')->getMessage());
    }

    public function testPostgresQueueProviderDelegatesEnqueueOperationsToStorage(): void
    {
        $storage = new InfrastructureQueueStorage();
        $provider = new PostgresQueueProvider($storage);
        $message = $this->queueMessage('message-1');

        $enqueue = $provider->enqueue($message);
        $batch = $provider->enqueueMany([$message, $this->queueMessage('message-2')]);

        self::assertSame('job-1', $enqueue->queueMessageId);
        self::assertSame(['enqueue:message-1', 'enqueueMany:2'], $storage->calls);
        self::assertCount(2, $batch->all());
        self::assertSame('batch-1', $batch->all()[0]->queueMessageId);
        self::assertSame('batch-2', $batch->all()[1]->queueMessageId);
    }

    public function testPostgresMessageConsumerDelegatesLifecycleOperationsToStorage(): void
    {
        $storage = new InfrastructureQueueStorage();
        $received = new ReceivedQueueMessage('job-1', $this->queueMessage('message-1'));
        $storage->nextMessage = $received;
        $consumer = new PostgresMessageConsumer($storage);
        $options = new ConsumerOptions('postgres', 'default');
        $failure = new RuntimeException('failed');

        self::assertSame($received, $consumer->next($options));
        $consumer->ack($received);
        $consumer->retry($received, $failure);
        $consumer->reject($received, $failure);
        $consumer->cancel($received, $failure);

        self::assertSame([
            'next:postgres:default',
            'ack:job-1',
            'retry:job-1:failed',
            'reject:job-1:failed',
            'cancel:job-1:failed',
        ], $storage->calls);
    }

    private function bootstrap(string $body): string
    {
        $file = \tempnam(\sys_get_temp_dir(), 'message-bus-infra-bootstrap-');
        self::assertIsString($file);
        \file_put_contents($file, "<?php\n\ndeclare(strict_types=1);\n\n" . $body . "\n");

        return $file;
    }

    private function queueMessage(string $messageId): QueueMessage
    {
        $createdAt = new DateTimeImmutable('2026-09-10T10:00:00+00:00');
        $envelope = new SerializedEnvelope(
            new SerializedMessage('infrastructure.message', 'application/json', '{}'),
            [],
            $messageId,
            null,
            'correlation-1',
            'async',
            'infrastructure.binding',
            $createdAt,
        );

        return new QueueMessage(
            'postgres',
            'default',
            $envelope,
            $messageId,
            'correlation-1',
            'async',
            'infrastructure.binding',
            $createdAt,
        );
    }
}

enum InfrastructureTransport: string
{
    case Postgres = 'postgres';
}

enum InfrastructureQueue: string
{
    case Critical = 'critical';
}

final class InfrastructureBootstrapFactory
{
    public static function runtime(): MessageBusRuntime
    {
        return new MessageBusRuntime(new InfrastructureBus());
    }

    public static function runner(): QueueWorkerRunner
    {
        return new QueueWorkerRunner(new InfrastructureConsumer(), new InfrastructureWorker());
    }
}

final class InfrastructureCallableService
{
    public function handle(string $prefix, int $value): string
    {
        return $prefix . ':' . $value;
    }
}

final class InfrastructureThrowingContainer implements ContainerInterface
{
    public function get(string $id): mixed
    {
        throw new InfrastructureContainerFailure('container failed');
    }

    public function has(string $id): bool
    {
        return true;
    }
}

final class InfrastructureContainerFailure extends RuntimeException implements ContainerExceptionInterface
{
}

final class InfrastructurePipelineLog
{
    /** @var list<string> */
    public static array $events = [];
}

final class InfrastructurePipelineMessage
{
    public function __construct(public readonly string $value)
    {
    }
}

final class InfrastructurePipelineAction
{
    public function handle(InfrastructurePipelineMessage $message): string
    {
        InfrastructurePipelineLog::$events[] = 'handler';

        return 'handled:' . $message->value;
    }
}

final class InfrastructureFirstInterceptor
{
    public function __invoke(MessageContextInterface $context, PipelineInterface $pipeline): mixed
    {
        InfrastructurePipelineLog::$events[] = 'first.before';
        $result = $pipeline->continue();
        InfrastructurePipelineLog::$events[] = 'first.after';

        return $result;
    }
}

final class InfrastructureSecondInterceptor
{
    public function __invoke(MessageContextInterface $context, PipelineInterface $pipeline): mixed
    {
        InfrastructurePipelineLog::$events[] = 'second.before';
        $result = $pipeline->continue();
        InfrastructurePipelineLog::$events[] = 'second.after';

        return $result;
    }
}

final class InfrastructureJsonResultDto
{
    /** @param array<string, string> $payload */
    public function __construct(
        public readonly string $status,
        public readonly array $payload,
    ) {
    }
}

final class InfrastructureNonPortableResult
{
    public function __construct(public readonly object $payload)
    {
    }
}

final class InfrastructureOtherResultDto
{
}

final class InfrastructureMessageContext implements MessageContextInterface
{
    private Envelope $envelope;

    public function __construct(object $message)
    {
        $this->envelope = new Envelope(
            $message,
            'message-1',
            'correlation-1',
            null,
            'default',
            'pipeline.binding',
            new DateTimeImmutable('2026-09-10T10:00:00+00:00'),
        );
    }

    public function envelope(): Envelope
    {
        return $this->envelope;
    }

    public function dispatch(object $message, PublishOptions $options = new PublishOptions()): mixed
    {
        throw new RuntimeException('Not used by infrastructure contract tests.');
    }

    public function dispatchAll(object $message, PublishOptions $options = new PublishOptions()): HandlerExecutionResultInterface
    {
        throw new RuntimeException('Not used by infrastructure contract tests.');
    }

    public function publish(object $message, PublishOptions $options = new PublishOptions()): PublishResult
    {
        throw new RuntimeException('Not used by infrastructure contract tests.');
    }
}

final class InfrastructureBus implements MessageBusInterface
{
    public function dispatch(
        object $message,
        PublishOptions $options = new PublishOptions(),
        ?Envelope $causation = null,
    ): mixed {
        throw new RuntimeException('Not used by infrastructure contract tests.');
    }

    public function dispatchAll(
        object $message,
        PublishOptions $options = new PublishOptions(),
        ?Envelope $causation = null,
    ): HandlerExecutionResultInterface {
        throw new RuntimeException('Not used by infrastructure contract tests.');
    }

    public function publish(
        object $message,
        PublishOptions $options = new PublishOptions(),
        ?Envelope $causation = null,
    ): PublishResult {
        throw new RuntimeException('Not used by infrastructure contract tests.');
    }

    /**
     * @param iterable<object|MessageBatchItem> $messages
     */
    public function publishMany(
        iterable $messages,
        PublishOptions $options = new PublishOptions(),
        ?Envelope $causation = null,
    ): PublishResult {
        throw new RuntimeException('Not used by infrastructure contract tests.');
    }

    public function dispatchPublishedSync(
        object $message,
        PublishOptions $options = new PublishOptions(),
        ?Envelope $causation = null,
    ): HandlerExecutionResultInterface {
        throw new RuntimeException('Not used by infrastructure contract tests.');
    }

    public function dispatchBindingSync(
        object $message,
        string|BackedEnum $bindingId,
        PublishOptions $options = new PublishOptions(),
        ?Envelope $causation = null,
    ): mixed {
        throw new RuntimeException('Not used by infrastructure contract tests.');
    }

    public function dispatchEnvelopeToBinding(Envelope $envelope): mixed
    {
        throw new RuntimeException('Not used by infrastructure contract tests.');
    }
}

final class InfrastructureConsumer implements MessageConsumerInterface
{
    public function next(ConsumerOptions $options): ?ReceivedQueueMessage
    {
        return null;
    }

    public function ack(ReceivedQueueMessage $message): void
    {
    }

    public function retry(ReceivedQueueMessage $message, \Throwable $reason): void
    {
    }

    public function reject(ReceivedQueueMessage $message, \Throwable $reason): void
    {
    }

    public function cancel(ReceivedQueueMessage $message, \Throwable $reason): void
    {
    }
}

final class InfrastructureQueueStorage implements PostgresQueueStorageInterface
{
    /** @var list<string> */
    public array $calls = [];

    public ?ReceivedQueueMessage $nextMessage = null;

    public function enqueue(QueueMessage $message): QueueEnqueueResult
    {
        $this->calls[] = 'enqueue:' . $message->messageId;

        return new QueueEnqueueResult('job-1', status: QueueJobState::Pending);
    }

    public function enqueueMany(iterable $messages): QueueBatchEnqueueResult
    {
        $count = 0;
        foreach ($messages as $message) {
            $count++;
        }

        $this->calls[] = 'enqueueMany:' . $count;

        return new QueueBatchEnqueueResult(
            new QueueEnqueueResult('batch-1', status: QueueJobState::Pending),
            new QueueEnqueueResult('batch-2', status: QueueJobState::Pending),
        );
    }

    public function next(ConsumerOptions $options): ?ReceivedQueueMessage
    {
        $this->calls[] = 'next:' . $options->transport . ':' . $options->queue;

        return $this->nextMessage;
    }

    public function ack(ReceivedQueueMessage $message): void
    {
        $this->calls[] = 'ack:' . $message->queueMessageId;
    }

    public function retry(ReceivedQueueMessage $message, \Throwable $reason): void
    {
        $this->calls[] = 'retry:' . $message->queueMessageId . ':' . $reason->getMessage();
    }

    public function reject(ReceivedQueueMessage $message, \Throwable $reason): void
    {
        $this->calls[] = 'reject:' . $message->queueMessageId . ':' . $reason->getMessage();
    }

    public function cancelReceived(ReceivedQueueMessage $message, \Throwable $reason): void
    {
        $this->calls[] = 'cancel:' . $message->queueMessageId . ':' . $reason->getMessage();
    }

    public function recoverStale(ConsumerOptions $options): int
    {
        return 0;
    }

    public function get(string $queueMessageId): ?QueueJobStatus
    {
        return null;
    }

    public function listByMessageId(string $messageId): array
    {
        return [];
    }

    public function listByCorrelationId(string $correlationId): array
    {
        return [];
    }

    public function cancel(string $queueMessageId): void
    {
    }

    public function requestCancellation(string $queueMessageId): void
    {
    }

    public function heartbeat(string $queueMessageId): void
    {
    }

    public function isCancellationRequested(string $queueMessageId): bool
    {
        return false;
    }
}

final class InfrastructureWorker implements QueueWorkerInterface
{
    public function handle(SerializedEnvelope $envelope): mixed
    {
        return null;
    }
}
