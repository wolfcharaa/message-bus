<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Cli\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Wolfcharaa\MessageBus\Worker\WorkerControlCommandType;

final class WorkerControlActionCommand extends AbstractWorkerControlCommand
{
    public function __construct(
        private readonly WorkerControlCommandType $type,
        string $name,
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setDescription('Send worker ' . $this->type->value . ' command.');
        $this->addBootstrapOption();
        $this->addTargetOptions();
        $this->addAuditOptions();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $receipt = $this->dispatchControl(
            $this->controlRuntime($input),
            $this->type,
            $this->target($input),
            $this->request($input),
        );

        return $this->writeReceipt($output, $receipt);
    }
}
