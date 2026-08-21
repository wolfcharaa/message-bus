<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Tests;

use PHPUnit\Framework\TestCase;
use Wolfcharaa\MessageBus\Worker\Postgres\PostgresWorkerControlSchemaGenerator;
use Wolfcharaa\MessageBus\Worker\WorkerControlTableDefinition;

final class PostgresWorkerControlContractTest extends TestCase
{
    public function testSchemaGeneratorIncludesWorkerControlTablesAndIndexes(): void
    {
        $sql = (new PostgresWorkerControlSchemaGenerator())->generate();

        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS "message_bus__worker_control_commands"', $sql);
        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS "message_bus__worker_desired_state"', $sql);
        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS "message_bus__worker_instances"', $sql);
        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS "message_bus__worker_child_instances"', $sql);
        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS "message_bus__worker_control_command_deliveries"', $sql);
        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS "message_bus__worker_control_command_acknowledgements"', $sql);
        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS "message_bus__worker_control_command_audit"', $sql);
        self::assertStringContainsString('command_id TEXT NOT NULL UNIQUE', $sql);
        self::assertStringContainsString('target_specificity INTEGER NOT NULL', $sql);
        self::assertStringContainsString('target_type TEXT NOT NULL', $sql);
        self::assertStringContainsString('concurrency_override INTEGER NULL', $sql);
        self::assertStringContainsString('worker_instance_id TEXT PRIMARY KEY', $sql);
        self::assertStringContainsString('actor_id TEXT NULL UNIQUE', $sql);
        self::assertStringContainsString('UNIQUE (command_id, worker_instance_id)', $sql);
        self::assertStringContainsString('message_bus__worker_control_commands_target_group_idx', $sql);
        self::assertStringContainsString('message_bus__worker_control_command_deliveries_command_idx', $sql);
        self::assertStringContainsString('message_bus__worker_control_command_acknowledgements_actor_stage_uidx', $sql);
        self::assertStringContainsString('message_bus__worker_control_command_audit_command_idx', $sql);
        self::assertStringContainsString('message_bus__worker_instances_target_idx', $sql);
        self::assertStringContainsString("VALUES ('worker_control', '5.1'", $sql);
    }

    public function testSchemaGeneratorUsesCustomTableNames(): void
    {
        $definition = new WorkerControlTableDefinition(
            commandsTable: 'message_bus__worker_commands_test',
            desiredStatesTable: 'message_bus__worker_desired_states_test',
            workerInstancesTable: 'message_bus__worker_instances_test',
            childInstancesTable: 'message_bus__worker_child_instances_test',
            acknowledgementsTable: 'message_bus__worker_command_acknowledgements_test',
            commandDeliveriesTable: 'message_bus__worker_control_command_deliveries_test',
            commandAuditTable: 'message_bus__worker_control_command_audit_test',
            schemaVersionsTable: 'message_bus__schema_versions_test',
        );

        $sql = (new PostgresWorkerControlSchemaGenerator())->generate($definition);

        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS "message_bus__worker_commands_test"', $sql);
        self::assertStringContainsString('message_bus__worker_command_acknowledgements_test_worker_idx', $sql);
        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS "message_bus__worker_control_command_deliveries_test"', $sql);
        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS "message_bus__worker_control_command_audit_test"', $sql);
        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS "message_bus__schema_versions_test"', $sql);
    }
}
