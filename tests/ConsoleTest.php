<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Wolfcharaa\MessageBus\Adapter\InMemoryDeliveryBackend;
use Wolfcharaa\MessageBus\Adapter\Symfony\Console;
use Wolfcharaa\MessageBus\Contract\Query;
use Wolfcharaa\MessageBus\Delivery\DeliveryMaintenance;
use Wolfcharaa\MessageBus\Delivery\DeliveryOperations;
use Wolfcharaa\MessageBus\Delivery\DeliveryPolicies;
use Wolfcharaa\MessageBus\Delivery\DeliveryPolicy;
use Wolfcharaa\MessageBus\Registry\ExplicitClassSource;
use Wolfcharaa\MessageBus\Registry\RegistryCompiler;

final class ConsoleTest extends TestCase
{
    public function testRegistryCheckReturnsVersionedJsonDiagnostics(): void
    {
        $application = Console::registry(
            new RegistryCompiler(),
            new ExplicitClassSource([ConsoleOrphanQuery::class]),
            sys_get_temp_dir() . '/message-bus-unused-registry.json',
        );
        $tester = new CommandTester($application->find('registry:check'));

        $code = $tester->execute(['--format' => 'json']);
        $payload = json_decode(trim($tester->getDisplay()), true, 512, JSON_THROW_ON_ERROR);
        if (!\is_array($payload) || array_is_list($payload)) {
            self::fail('Command JSON output must be an object.');
        }
        $diagnostics = $payload['diagnostics'] ?? null;
        if (!\is_array($diagnostics) || !isset($diagnostics[0]) || !\is_array($diagnostics[0])) {
            self::fail('Command JSON output must contain diagnostics.');
        }
        $firstDiagnostic = $diagnostics[0];

        self::assertSame(2, $code);
        self::assertSame(1, $payload['schema']);
        self::assertSame('registry:check', $payload['command']);
        self::assertFalse($payload['valid']);
        self::assertSame('MBR014', $firstDiagnostic['code'] ?? null);
    }

    public function testDeliveryConsoleExposesBoundedDryRunOperations(): void
    {
        $backend = new InMemoryDeliveryBackend();
        $application = Console::delivery(
            new DeliveryOperations($backend),
            new DeliveryMaintenance($backend),
            new DeliveryPolicies(['manual' => new DeliveryPolicy('manual')]),
        );
        foreach (['delivery:status', 'delivery:cancel', 'delivery:replay', 'delivery:recover', 'delivery:prune'] as $name) {
            self::assertTrue($application->has($name));
        }

        $tester = new CommandTester($application->find('delivery:prune'));
        self::assertSame(0, $tester->execute([
            '--terminal-before' => '2026-11-22T10:00:00+00:00',
            '--batch-size' => '10',
            '--format' => 'json',
        ]));
        $payload = json_decode(trim($tester->getDisplay()), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertFalse($payload['executed'] ?? true);
        self::assertSame(0, $payload['changed'] ?? null);
    }
}

/** @implements Query<string> */
final readonly class ConsoleOrphanQuery implements Query {}
