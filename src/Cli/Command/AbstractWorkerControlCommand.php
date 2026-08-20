<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Cli\Command;

use DateTimeImmutable;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Wolfcharaa\MessageBus\Cli\BootstrapResolver;
use Wolfcharaa\MessageBus\Runtime\MessageBusRuntime;
use Wolfcharaa\MessageBus\Runtime\WorkerControlRuntime;
use Wolfcharaa\MessageBus\Worker\WorkerControlCommandReceipt;
use Wolfcharaa\MessageBus\Worker\WorkerControlCommandType;
use Wolfcharaa\MessageBus\Worker\WorkerControlRequest;
use Wolfcharaa\MessageBus\Worker\WorkerMode;
use Wolfcharaa\MessageBus\Worker\WorkerTarget;

abstract class AbstractWorkerControlCommand extends Command
{
    protected function addBootstrapOption(): void
    {
        $this->addOption('bootstrap', null, InputOption::VALUE_REQUIRED);
    }

    protected function addTargetOptions(): void
    {
        $this
            ->addOption('worker-id', null, InputOption::VALUE_REQUIRED)
            ->addOption('worker-name', null, InputOption::VALUE_REQUIRED)
            ->addOption('worker-instance-id', null, InputOption::VALUE_REQUIRED)
            ->addOption('group', null, InputOption::VALUE_REQUIRED)
            ->addOption('transport', null, InputOption::VALUE_REQUIRED)
            ->addOption('queue', null, InputOption::VALUE_REQUIRED)
            ->addOption('flow', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY)
            ->addOption('binding-id', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY)
            ->addOption('binding-pattern', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY)
            ->addOption('mode', null, InputOption::VALUE_REQUIRED)
            ->addOption('host', null, InputOption::VALUE_REQUIRED)
            ->addOption('all', null, InputOption::VALUE_NONE);
    }

    protected function addAuditOptions(): void
    {
        $this
            ->addOption('created-by', null, InputOption::VALUE_REQUIRED)
            ->addOption('source', null, InputOption::VALUE_REQUIRED, default: 'cli')
            ->addOption('reason', null, InputOption::VALUE_REQUIRED)
            ->addOption('request-id', null, InputOption::VALUE_REQUIRED)
            ->addOption('correlation-id', null, InputOption::VALUE_REQUIRED)
            ->addOption('expires-at', null, InputOption::VALUE_REQUIRED)
            ->addOption('expires-in', null, InputOption::VALUE_REQUIRED)
            ->addOption('idempotency-key', null, InputOption::VALUE_REQUIRED)
            ->addOption('override', null, InputOption::VALUE_NONE);
    }

    protected function controlRuntime(InputInterface $input): WorkerControlRuntime
    {
        $runtime = (new BootstrapResolver())->resolve($input->getOption('bootstrap'));

        if (!$runtime instanceof MessageBusRuntime || $runtime->workerControlRuntime() === null) {
            throw new \RuntimeException('Worker control command requires MessageBusRuntime with WorkerControlRuntime.');
        }

        return $runtime->workerControlRuntime();
    }

    protected function target(InputInterface $input, bool $defaultAll = false): WorkerTarget
    {
        if ($defaultAll && !$this->hasTargetFilters($input) && !(bool) $input->getOption('all')) {
            return WorkerTarget::all();
        }

        return new WorkerTarget(
            workerId: $input->getOption('worker-id'),
            workerName: $input->getOption('worker-name'),
            workerInstanceId: $input->getOption('worker-instance-id'),
            workerGroup: $input->getOption('group'),
            transport: $input->getOption('transport'),
            queue: $input->getOption('queue'),
            flows: $input->getOption('flow'),
            bindingIds: $input->getOption('binding-id'),
            bindingPatterns: $input->getOption('binding-pattern'),
            mode: $input->getOption('mode') !== null ? WorkerMode::from($input->getOption('mode')) : null,
            host: $input->getOption('host'),
            all: (bool) $input->getOption('all'),
        );
    }

    protected function request(InputInterface $input): WorkerControlRequest
    {
        return new WorkerControlRequest(
            createdBy: $input->getOption('created-by'),
            source: $input->getOption('source') ?? 'cli',
            reason: $input->getOption('reason'),
            requestId: $input->getOption('request-id'),
            correlationId: $input->getOption('correlation-id'),
            expiresAt: $this->expiresAt($input),
            idempotencyKey: $input->getOption('idempotency-key'),
            override: (bool) $input->getOption('override'),
        );
    }

    protected function writeReceipt(OutputInterface $output, WorkerControlCommandReceipt $receipt): int
    {
        $output->writeln(\sprintf(
            'command=%s type=%s duplicate=%s',
            $receipt->commandId,
            $receipt->type->value,
            $receipt->duplicate ? 'yes' : 'no',
        ));

        return Command::SUCCESS;
    }

    protected function dispatchControl(
        WorkerControlRuntime $runtime,
        WorkerControlCommandType $type,
        WorkerTarget $target,
        WorkerControlRequest $request,
    ): WorkerControlCommandReceipt {
        return match ($type) {
            WorkerControlCommandType::Pause => $runtime->controlService()->pause($target, $request),
            WorkerControlCommandType::Resume => $runtime->controlService()->resume($target, $request),
            WorkerControlCommandType::Drain => $runtime->controlService()->drain($target, $request),
            WorkerControlCommandType::Stop => $runtime->controlService()->stop($target, $request),
            WorkerControlCommandType::Kill => $runtime->controlService()->kill($target, $request),
            WorkerControlCommandType::Restart => $runtime->controlService()->restart($target, $request),
        };
    }

    private function hasTargetFilters(InputInterface $input): bool
    {
        return $input->getOption('worker-id') !== null
            || $input->getOption('worker-name') !== null
            || $input->getOption('worker-instance-id') !== null
            || $input->getOption('group') !== null
            || $input->getOption('transport') !== null
            || $input->getOption('queue') !== null
            || $input->getOption('flow') !== []
            || $input->getOption('binding-id') !== []
            || $input->getOption('binding-pattern') !== []
            || $input->getOption('mode') !== null
            || $input->getOption('host') !== null;
    }

    private function expiresAt(InputInterface $input): ?DateTimeImmutable
    {
        if ($input->getOption('expires-at') !== null) {
            return new DateTimeImmutable($input->getOption('expires-at'));
        }

        if ($input->getOption('expires-in') !== null) {
            return (new DateTimeImmutable('now'))->modify('+' . (int) $input->getOption('expires-in') . ' seconds');
        }

        return null;
    }
}
