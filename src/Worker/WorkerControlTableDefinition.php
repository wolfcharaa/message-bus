<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Worker;

final class WorkerControlTableDefinition
{
    public function __construct(
        public readonly string $commandsTable = 'message_bus__worker_control_commands',
        public readonly string $desiredStatesTable = 'message_bus__worker_desired_state',
        public readonly string $workerInstancesTable = 'message_bus__worker_instances',
        public readonly string $childInstancesTable = 'message_bus__worker_child_instances',
        public readonly string $acknowledgementsTable = 'message_bus__worker_control_command_acknowledgements',
        public readonly string $commandDeliveriesTable = 'message_bus__worker_control_command_deliveries',
        public readonly string $commandAuditTable = 'message_bus__worker_control_command_audit',
        public readonly string $schemaVersionsTable = 'message_bus__schema_versions',
    ) {
    }
}
