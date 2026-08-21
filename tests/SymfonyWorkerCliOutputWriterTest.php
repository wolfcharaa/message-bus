<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;
use Wolfcharaa\MessageBus\Cli\SymfonyWorkerCliOutputWriter;
use Wolfcharaa\MessageBus\Worker\WorkerCliOutputConfig;
use Wolfcharaa\MessageBus\Worker\WorkerCliOutputFormat;
use Wolfcharaa\MessageBus\Worker\WorkerCliOutputVerbosity;

final class SymfonyWorkerCliOutputWriterTest extends TestCase
{
    public function testNormalVerbositySkipsDebugHeartbeat(): void
    {
        $output = new BufferedOutput();
        $writer = new SymfonyWorkerCliOutputWriter($output, new WorkerCliOutputConfig(
            verbosity: WorkerCliOutputVerbosity::Normal,
        ));

        $writer->write(
            'info',
            'worker.heartbeat',
            'Auto worker heartbeat.',
            ['worker_instance_id' => 'worker-1'],
            WorkerCliOutputVerbosity::Debug,
        );

        self::assertSame('', $output->fetch());
    }

    public function testDebugVerbosityWritesHeartbeatAsJsonLine(): void
    {
        $output = new BufferedOutput();
        $writer = new SymfonyWorkerCliOutputWriter($output, new WorkerCliOutputConfig(
            verbosity: WorkerCliOutputVerbosity::Debug,
            format: WorkerCliOutputFormat::Json,
        ));

        $writer->write(
            'info',
            'worker.heartbeat',
            'Auto worker heartbeat.',
            ['worker_instance_id' => 'worker-1'],
            WorkerCliOutputVerbosity::Debug,
        );

        $line = \trim($output->fetch());
        $payload = \json_decode($line, true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('info', $payload['level']);
        self::assertSame('worker.heartbeat', $payload['event']);
        self::assertSame('worker-1', $payload['context']['worker_instance_id']);
    }

    public function testQuietVerbosityStillWritesErrors(): void
    {
        $output = new BufferedOutput();
        $writer = new SymfonyWorkerCliOutputWriter($output, new WorkerCliOutputConfig(
            verbosity: WorkerCliOutputVerbosity::Quiet,
        ));

        $writer->write(
            'error',
            'worker.heartbeat_failed',
            'Worker heartbeat failed after retry policy.',
            ['worker_instance_id' => 'worker-1'],
            WorkerCliOutputVerbosity::Debug,
        );

        self::assertStringContainsString('worker.heartbeat_failed', $output->fetch());
    }
}
