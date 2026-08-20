<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Cli\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Wolfcharaa\MessageBus\Cli\BootstrapResolver;
use Wolfcharaa\MessageBus\Queue\ConsumerOptions;
use Wolfcharaa\MessageBus\Queue\PcntlAutoWorkerRunner;
use Wolfcharaa\MessageBus\Queue\PcntlAutoWorkerRunnerOptions;
use Wolfcharaa\MessageBus\Queue\QueueWorkerRunner;
use Wolfcharaa\MessageBus\Queue\QueueWorkerRunnerOptions;
use Wolfcharaa\MessageBus\Runtime\MessageBusRuntime;

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
            ->addOption('mode', null, InputOption::VALUE_REQUIRED, default: 'single')
            ->addOption('workers', null, InputOption::VALUE_REQUIRED, default: 2)
            ->addOption('max-messages', null, InputOption::VALUE_REQUIRED)
            ->addOption('max-runtime', null, InputOption::VALUE_REQUIRED)
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
        );

        if (!\in_array($mode, ['single', 'auto'], true)) {
            throw new \InvalidArgumentException('Worker mode must be `single` or `auto`.');
        }

        if ($mode === 'auto') {
            if (!$runtime instanceof MessageBusRuntime || $runtime->consumer() === null || $runtime->worker() === null) {
                throw new \RuntimeException('Auto worker mode requires MessageBusRuntime with consumer and worker.');
            }

            $bootstrap = $input->getOption('bootstrap');
            $childRuntime = static function () use ($bootstrap): MessageBusRuntime {
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
            ))->run(
                $consumerOptions,
                new PcntlAutoWorkerRunnerOptions(
                    maxWorkers: (int) $input->getOption('workers'),
                    maxMessages: $input->getOption('max-messages') !== null ? (int) $input->getOption('max-messages') : null,
                    maxRuntimeSeconds: $input->getOption('max-runtime') !== null ? (int) $input->getOption('max-runtime') : null,
                    stopWhenEmpty: (bool) $input->getOption('stop-when-empty'),
                ),
            );

            return $this->printResult($output, $result);
        }

        $runner = $runtime instanceof QueueWorkerRunner ? $runtime : $runtime->runner();

        if (!$runner instanceof QueueWorkerRunner) {
            throw new \RuntimeException('MessageBus runtime does not provide QueueWorkerRunner.');
        }

        $result = $runner->run(
            $consumerOptions,
            new QueueWorkerRunnerOptions(
                maxMessages: $input->getOption('max-messages') !== null ? (int) $input->getOption('max-messages') : null,
                maxRuntimeSeconds: $input->getOption('max-runtime') !== null ? (int) $input->getOption('max-runtime') : null,
                stopWhenEmpty: (bool) $input->getOption('stop-when-empty'),
            ),
        );

        return $this->printResult($output, $result);
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

        return Command::SUCCESS;
    }
}
