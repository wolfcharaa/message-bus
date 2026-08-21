<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Cli\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Wolfcharaa\MessageBus\Cli\BootstrapResolver;
use Wolfcharaa\MessageBus\Cli\ExitCode;
use Wolfcharaa\MessageBus\Cli\SymfonyWorkerCliOutputWriter;
use Wolfcharaa\MessageBus\Postgres\PostgresSchemaComponent;
use Wolfcharaa\MessageBus\Postgres\PostgresSchemaValidationResult;
use Wolfcharaa\MessageBus\Queue\ConsumerOptions;
use Wolfcharaa\MessageBus\Queue\PcntlAutoWorkerRunner;
use Wolfcharaa\MessageBus\Queue\PcntlAutoWorkerRunnerOptions;
use Wolfcharaa\MessageBus\Queue\QueueWorkerRunner;
use Wolfcharaa\MessageBus\Queue\QueueWorkerRunnerOptions;
use Wolfcharaa\MessageBus\Queue\ReceivedQueueMessage;
use Wolfcharaa\MessageBus\Runtime\MessageBusRuntime;
use Wolfcharaa\MessageBus\Worker\QueueJobWorkerRuntimeControl;
use Wolfcharaa\MessageBus\Worker\WorkerCliOutputConfig;

#[AsCommand(name: 'worker:run')]
final class WorkerRunCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('bootstrap', null, InputOption::VALUE_REQUIRED)
            ->addOption('transport', null, InputOption::VALUE_REQUIRED, default: 'postgres')
            ->addOption('queue', null, InputOption::VALUE_REQUIRED, default: 'default')
            ->addOption('worker-id', null, InputOption::VALUE_REQUIRED, default: 'message-bus-worker')
            ->addOption('worker-name', null, InputOption::VALUE_REQUIRED)
            ->addOption('worker-group', null, InputOption::VALUE_REQUIRED)
            ->addOption('worker-instance-id', null, InputOption::VALUE_REQUIRED)
            ->addOption('host', null, InputOption::VALUE_REQUIRED)
            ->addOption('mode', null, InputOption::VALUE_REQUIRED, default: 'single')
            ->addOption('workers', null, InputOption::VALUE_REQUIRED, default: 2)
            ->addOption('flow', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY)
            ->addOption('binding-id', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY)
            ->addOption('binding-pattern', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY)
            ->addOption('max-messages', null, InputOption::VALUE_REQUIRED)
            ->addOption('max-runtime', null, InputOption::VALUE_REQUIRED)
            ->addOption('control-poll-interval', null, InputOption::VALUE_REQUIRED, default: 1000)
            ->addOption('heartbeat-interval', null, InputOption::VALUE_REQUIRED, default: 1000)
            ->addOption('storage-failure-backoff', null, InputOption::VALUE_REQUIRED, default: 1000)
            ->addOption('max-heartbeat-failures', null, InputOption::VALUE_REQUIRED, default: 3)
            ->addOption('force-kill-timeout', null, InputOption::VALUE_REQUIRED, default: 5)
            ->addOption('restart-exit-code', null, InputOption::VALUE_REQUIRED, default: 75)
            ->addOption('output-verbosity', null, InputOption::VALUE_REQUIRED, default: 'normal')
            ->addOption('output-format', null, InputOption::VALUE_REQUIRED, default: 'text')
            ->addOption('stop-when-empty', null, InputOption::VALUE_NONE);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $runtime = (new BootstrapResolver())->resolve($input->getOption('bootstrap'));
        $mode = $input->getOption('mode');
        $consumerOptions = new ConsumerOptions(
            $input->getOption('transport'),
            $input->getOption('queue'),
            workerId: $input->getOption('worker-id'),
            flows: $input->getOption('flow'),
            bindingIds: $input->getOption('binding-id'),
            bindingPatterns: $input->getOption('binding-pattern'),
        );
        $outputWriter = new SymfonyWorkerCliOutputWriter($output, WorkerCliOutputConfig::fromArray([
            'verbosity' => $input->getOption('output-verbosity'),
            'format' => $input->getOption('output-format'),
        ]));

        if (!\in_array($mode, ['single', 'auto'], true)) {
            throw new \InvalidArgumentException('Worker mode must be `single` or `auto`.');
        }

        if ($mode === 'auto') {
            if (!$runtime instanceof MessageBusRuntime || $runtime->consumer() === null || $runtime->worker() === null) {
                throw new \RuntimeException('Auto worker mode requires MessageBusRuntime with consumer and worker.');
            }

            $extensionExitCode = $this->validateAutoModeExtensions($output);
            if ($extensionExitCode !== null) {
                return $extensionExitCode;
            }

            $schemaExitCode = $this->validatePostgresSchemaForAutoMode($runtime, $consumerOptions->transport, $output);
            if ($schemaExitCode !== null) {
                return $schemaExitCode;
            }

            $bootstrap = $input->getOption('bootstrap');
            $childRuntime = static function () use ($bootstrap): MessageBusRuntime {
                static $runtime = null;

                if ($runtime instanceof MessageBusRuntime) {
                    return $runtime;
                }

                $runtime = (new BootstrapResolver())->resolve($bootstrap);
                if (!$runtime instanceof MessageBusRuntime) {
                    throw new \RuntimeException('Auto worker child bootstrap must return MessageBusRuntime or PSR-11 container.');
                }

                return $runtime;
            };

            $result = (new PcntlAutoWorkerRunner(
                $runtime->consumer(),
                $runtime->worker(),
                childConsumerFactory: static fn () => $childRuntime()->consumer(),
                childWorkerFactory: static fn () => $childRuntime()->worker(),
                queueControl: $runtime->queueControl(),
                childWorkerRuntimeControlFactory: static function (
                    ReceivedQueueMessage $message,
                    string $childInstanceId,
                ) use ($childRuntime): QueueJobWorkerRuntimeControl {
                    $runtime = $childRuntime();

                    return new QueueJobWorkerRuntimeControl(
                        queueMessageId: $message->queueMessageId,
                        queueControl: $runtime->queueControl(),
                        workerRegistry: $runtime->workerControlRuntime()?->workerRegistry(),
                        childInstanceId: $childInstanceId,
                    );
                },
                workerControlRuntime: $runtime->workerControlRuntime(),
                output: $outputWriter,
            ))->run(
                $consumerOptions,
                new PcntlAutoWorkerRunnerOptions(
                    maxWorkers: (int) $input->getOption('workers'),
                    maxMessages: $input->getOption('max-messages') !== null ? (int) $input->getOption('max-messages') : null,
                    maxRuntimeSeconds: $input->getOption('max-runtime') !== null ? (int) $input->getOption('max-runtime') : null,
                    stopWhenEmpty: (bool) $input->getOption('stop-when-empty'),
                    workerName: $input->getOption('worker-name') ?? $input->getOption('worker-id'),
                    workerGroup: $input->getOption('worker-group'),
                    workerInstanceId: $input->getOption('worker-instance-id'),
                    host: $input->getOption('host'),
                    controlPollIntervalMilliseconds: (int) $input->getOption('control-poll-interval'),
                    heartbeatIntervalMilliseconds: (int) $input->getOption('heartbeat-interval'),
                    forceKillTimeoutSeconds: (int) $input->getOption('force-kill-timeout'),
                    restartExitCode: (int) $input->getOption('restart-exit-code'),
                    storageFailureBackoffMilliseconds: (int) $input->getOption('storage-failure-backoff'),
                    maxConsecutiveHeartbeatFailures: (int) $input->getOption('max-heartbeat-failures'),
                ),
            );

            return $this->printResult($output, $result);
        }

        $runner = $runtime instanceof QueueWorkerRunner ? $runtime : $runtime->runner();

        if (!$runner instanceof QueueWorkerRunner) {
            throw new \RuntimeException('MessageBus runtime does not provide QueueWorkerRunner.');
        }

        $outputWriter->write('info', 'worker.started', 'Worker started.', [
            'mode' => 'single',
            'transport' => $consumerOptions->transport,
            'queue' => $consumerOptions->queue,
            'worker_id' => $consumerOptions->workerId,
        ]);

        try {
            $result = $runner->run(
                $consumerOptions,
                new QueueWorkerRunnerOptions(
                    maxMessages: $input->getOption('max-messages') !== null ? (int) $input->getOption('max-messages') : null,
                    maxRuntimeSeconds: $input->getOption('max-runtime') !== null ? (int) $input->getOption('max-runtime') : null,
                    stopWhenEmpty: (bool) $input->getOption('stop-when-empty'),
                ),
            );
        } catch (\Throwable $error) {
            $outputWriter->write('error', 'worker.fatal', 'Worker failed.', [
                'mode' => 'single',
                'transport' => $consumerOptions->transport,
                'queue' => $consumerOptions->queue,
                'exception' => $error,
            ]);

            throw $error;
        }

        $outputWriter->write('info', 'worker.stopped', 'Worker stopped.', [
            'mode' => 'single',
            'transport' => $consumerOptions->transport,
            'queue' => $consumerOptions->queue,
            'handled' => $result->handled,
            'succeeded' => $result->succeeded,
            'retried' => $result->retried,
            'rejected' => $result->rejected,
            'cancelled' => $result->cancelled,
        ]);

        return $this->printResult($output, $result);
    }

    private function validateAutoModeExtensions(OutputInterface $output): ?int
    {
        $missing = [];
        foreach (['pcntl', 'posix'] as $extension) {
            if (!\extension_loaded($extension)) {
                $missing[] = $extension;
            }
        }

        if ($missing === []) {
            return null;
        }

        $errorOutput = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $errorOutput->writeln(\sprintf(
            '<error>Auto worker mode requires PHP extensions: %s.</error>',
            \implode(', ', $missing),
        ));

        return ExitCode::MissingRequiredExtension->value;
    }

    private function validatePostgresSchemaForAutoMode(
        MessageBusRuntime $runtime,
        string $transport,
        OutputInterface $output,
    ): ?int {
        if ($transport !== 'postgres' || $runtime->postgresSchemaValidator() === null) {
            return null;
        }

        $result = $runtime->postgresSchemaValidator()->validate([
            PostgresSchemaComponent::Queue,
            PostgresSchemaComponent::WorkerControl,
        ]);

        if ($result->isValid()) {
            return null;
        }

        $errorOutput = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $errorOutput->writeln('<error>MessageBus PostgreSQL schema is not compatible with worker auto mode.</error>');
        $this->printSchemaValidationResult($errorOutput, $result);

        return $result->hasIssueCode('schema.version_mismatch') || $result->hasIssueCode('schema.version_missing')
            ? ExitCode::SchemaMismatch->value
            : ExitCode::SchemaValidationFailed->value;
    }

    private function printSchemaValidationResult(OutputInterface $output, PostgresSchemaValidationResult $result): void
    {
        foreach ($result->requiredVersions as $component => $required) {
            $current = $result->currentVersions[$component] ?? null;
            $output->writeln(\sprintf('component=%s required=%s current=%s', $component, $required, $current ?? 'missing'));
        }

        foreach ($result->issues as $issue) {
            $output->writeln(\sprintf('[%s] %s', $issue->code, $issue->message));
        }
    }

    private function printResult(OutputInterface $output, \Wolfcharaa\MessageBus\Queue\QueueWorkerRunResult $result): int
    {
        $output->writeln(\sprintf(
            'handled=%d succeeded=%d retried=%d rejected=%d cancelled=%d',
            $result->handled,
            $result->succeeded,
            $result->retried,
            $result->rejected,
            $result->cancelled,
        ));

        return $result->exitCode ?? Command::SUCCESS;
    }
}
