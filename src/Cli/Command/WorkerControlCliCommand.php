<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Cli\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Wolfcharaa\MessageBus\Worker\WorkerControlCommandType;

final class WorkerControlCliCommand extends AbstractWorkerControlCommand
{
    public function __construct()
    {
        parent::__construct('worker:control');
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Send a worker control command.')
            ->addOption('command', null, InputOption::VALUE_REQUIRED);
        $this->addBootstrapOption();
        $this->addTargetOptions();
        $this->addAuditOptions();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $type = WorkerControlCommandType::from((string) $input->getOption('command'));
        $receipt = $this->dispatchControl(
            $this->controlRuntime($input),
            $type,
            $this->target($input),
            $this->request($input),
        );

        return $this->writeReceipt($output, $receipt);
    }
}
