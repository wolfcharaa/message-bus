<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Postgres;

interface PostgresSchemaValidatorInterface
{
    /**
     * @param list<PostgresSchemaComponent>|null $components
     */
    public function validate(?array $components = null): PostgresSchemaValidationResult;
}
