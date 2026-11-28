<?php

declare(strict_types=1);

namespace MessageBusDurableExample;

use Wolfcharaa\MessageBus\Adapter\Postgres\PostgresRuntime;
use Wolfcharaa\MessageBus\Attribute\Handler;
use Wolfcharaa\MessageBus\Attribute\MessageName;
use Wolfcharaa\MessageBus\Attribute\Payload;
use Wolfcharaa\MessageBus\Contract\Command;
use Wolfcharaa\MessageBus\Delivery\DeliveryPolicies;
use Wolfcharaa\MessageBus\Delivery\DeliveryPolicy;
use Wolfcharaa\MessageBus\HandlerServices;
use Wolfcharaa\MessageBus\Identity\DeliveryId;
use Wolfcharaa\MessageBus\Receipt\CommandReceipt;
use Wolfcharaa\MessageBus\Registry\ExplicitClassSource;
use Wolfcharaa\MessageBus\Registry\RegistryCompiler;
use Wolfcharaa\MessageBus\Routing\DeliveryMode;
use Wolfcharaa\MessageBus\Routing\DeliveryRoute;
use Wolfcharaa\MessageBus\Routing\DeliveryRoutes;
use Wolfcharaa\MessageBus\Routing\RuntimeConfigurationRule;
use Wolfcharaa\MessageBus\Worker\WorkerProfile;

require dirname(__DIR__) . '/autoload.php';

#[MessageName('example.send-welcome')]
#[Payload]
final readonly class SendWelcome implements Command
{
    public function __construct(public string $email) {}
}

final class SendWelcomeHandler
{
    public int $calls = 0;

    #[Handler(bindingId: 'command.send-welcome')]
    public function __invoke(SendWelcome $command): void
    {
        ++$this->calls;
    }
}

$dsn = getenv('MESSAGE_BUS_EXAMPLE_PGSQL_DSN');
if (!is_string($dsn) || $dsn === '') {
    fwrite(STDERR, "Set MESSAGE_BUS_EXAMPLE_PGSQL_DSN for a disposable PostgreSQL database.\n");
    exit(2);
}
$schema = getenv('MESSAGE_BUS_EXAMPLE_PGSQL_SCHEMA') ?: 'message_bus_example';
if (!is_string($schema) || preg_match('/^[a-z_][a-z0-9_]{0,62}$/D', $schema) !== 1) {
    throw new \InvalidArgumentException('MESSAGE_BUS_EXAMPLE_PGSQL_SCHEMA is invalid.');
}
$pdo = new \PDO(
    $dsn,
    (string) getenv('MESSAGE_BUS_EXAMPLE_PGSQL_USER'),
    (string) getenv('MESSAGE_BUS_EXAMPLE_PGSQL_PASSWORD'),
    [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
);
$pdo->exec('CREATE SCHEMA IF NOT EXISTS "' . $schema . '"');
$handler = new SendWelcomeHandler();
$routes = new DeliveryRoutes([
    'command.send-welcome' => new DeliveryRoute(DeliveryMode::Durable),
]);
$policies = new DeliveryPolicies(['default' => new DeliveryPolicy('default')]);
$registry = (new RegistryCompiler(rules: [
    new RuntimeConfigurationRule($routes, $policies),
]))->compile(new ExplicitClassSource([
    SendWelcome::class,
    SendWelcomeHandler::class,
]))->requireRegistry();
$services = new HandlerServices([SendWelcomeHandler::class => $handler]);
$runtime = PostgresRuntime::create(
    pdo: $pdo,
    registry: $registry,
    handlers: $services,
    routes: $routes,
    policies: $policies,
    schema: $schema,
);
$runtime->schemaManager->migrate();
$runtime->schemaManager->validate();
$runtime->schemaManager->validateCatalog();

$receipt = $runtime->bus->dispatch(new SendWelcome('ada@example.test'));
if (!$receipt instanceof CommandReceipt || !$receipt->entry->deliveryId instanceof DeliveryId) {
    throw new \RuntimeException('Command did not reach durable acceptance.');
}
$iteration = $runtime->worker->runOnce(new WorkerProfile('example', 'default'), 'example:' . getmypid());
$status = $runtime->backend->status($receipt->entry->deliveryId);

echo json_encode([
    'receipt' => $receipt->entry->outcome->value,
    'worker' => $iteration->value,
    'status' => $status?->state->value,
    'handler_calls' => $handler->calls,
], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT), PHP_EOL;
