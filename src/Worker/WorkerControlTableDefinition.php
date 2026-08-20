<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Worker;

final class WorkerControlTableDefinition
{
    public function __construct(
        public readonly string $commandsTable = 'message_bus__worker_commands',
        public readonly string $desiredStatesTable = 'message_bus__worker_desired_states',
        public readonly string $workerInstancesTable = 'message_bus__worker_instances',
        public readonly string $childInstancesTable = 'message_bus__worker_child_instances',
        public readonly string $acknowledgementsTable = 'message_bus__worker_command_acknowledgements',
    ) {
    }
}
