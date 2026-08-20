<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Cli\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Wolfcharaa\MessageBus\Dumper\CompiledRegistryFileWriter;
use Wolfcharaa\MessageBus\Registry\MessageRegistryDefinition;

#[AsCommand(name: 'registry:compile')]
final class RegistryCompileCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('bootstrap', null, InputOption::VALUE_REQUIRED)
            ->addOption('output', null, InputOption::VALUE_REQUIRED);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $value = require $input->getOption('bootstrap');

        if (!$value instanceof MessageRegistryDefinition) {
            throw new \RuntimeException('registry:compile bootstrap must return MessageRegistryDefinition.');
        }

        $file = (new CompiledRegistryFileWriter())->write($value, (string) $input->getOption('output'));
        $output->writeln('compiled=' . $file);

        return Command::SUCCESS;
    }
}
