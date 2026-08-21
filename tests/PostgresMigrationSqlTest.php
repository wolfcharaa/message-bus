<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Tests;

use PHPUnit\Framework\TestCase;
use Wolfcharaa\MessageBus\Queue\Postgres\PostgresQueueSchemaGenerator;
use Wolfcharaa\MessageBus\Worker\Postgres\PostgresWorkerControlSchemaGenerator;

final class PostgresMigrationSqlTest extends TestCase
{
    public function testQueueMigrationSqlMatchesGenerator(): void
    {
        self::assertSame(
            (new PostgresQueueSchemaGenerator())->generate() . PHP_EOL,
            $this->migrationSql('queue.sql'),
        );
    }

    public function testWorkerControlMigrationSqlMatchesGenerator(): void
    {
        self::assertSame(
            (new PostgresWorkerControlSchemaGenerator())->generate() . PHP_EOL,
            $this->migrationSql('worker-control.sql'),
        );
    }

    public function testAllMigrationSqlMatchesGenerators(): void
    {
        $expected = (new PostgresQueueSchemaGenerator())->generate()
            . PHP_EOL
            . PHP_EOL
            . (new PostgresWorkerControlSchemaGenerator())->generate()
            . PHP_EOL;

        self::assertSame($expected, $this->migrationSql('all.sql'));
    }

    private function migrationSql(string $file): string
    {
        $path = __DIR__ . '/../resources/postgres/schema/5.1/' . $file;
        self::assertFileExists($path);

        return (string) \file_get_contents($path);
    }
}
