<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Internal\Postgres;

/** @internal */
final class QualifiedSql
{
    public static function validateSchema(string $schema): void
    {
        if (preg_match('/^[a-z_][a-z0-9_]{0,62}$/D', $schema) !== 1) {
            throw new \InvalidArgumentException('PostgreSQL schema name is invalid.');
        }
    }

    public static function qualify(string $sql, string $schema): string
    {
        self::validateSchema($schema);
        $qualified = preg_replace(
            '/(?<![A-Za-z0-9_."])\b(message_bus__[a-z0-9_]+)\b/',
            self::quote($schema) . '.$1',
            $sql,
        );

        return \is_string($qualified) ? $qualified : throw new \RuntimeException('SQL qualification failed.');
    }

    public static function quote(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }
}
