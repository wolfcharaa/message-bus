# MessageBus

A typed PHP 8.3+ message bus with a deterministic registry and optional durable
delivery through PostgreSQL and PCNTL. The library exposes one
`MessageBus::dispatch()` method for Query, Request, Command, and Event. The host
application retains DI, business transactions, deployment, and authorization.

## Installation

```bash
composer require romanfedorskij/message-bus:^7.0@alpha
```

The durable profile requires `ext-pdo_pgsql`, `ext-pcntl`, and `ext-posix`.
Symfony Console and PSR-3 are only needed by their adapters; core requires PHP,
JSON, and `psr/clock`.

## Choose a path

- [Orchestration without a queue](docs/en/getting-started/orchestration.md) —
  Query/Request and inline Event in the caller process.
- [Durable PostgreSQL](docs/en/getting-started/durable-postgres.md) — Command,
  durable Event, delivery status/control, and worker processes.

The shortest observable path:

```php
/** @implements \Wolfcharaa\MessageBus\Contract\Query<string> */
final readonly class Greeting implements \Wolfcharaa\MessageBus\Contract\Query
{
    public function __construct(public string $name) {}
}

final class GreetingHandler
{
    #[\Wolfcharaa\MessageBus\Attribute\Handler]
    public function __invoke(Greeting $query): string
    {
        return 'Hello ' . $query->name;
    }
}

$answer = $bus->dispatch(new Greeting('Ada'));
```

The complete composition is in
[`examples/orchestration/run.php`](examples/orchestration/run.php). Production
compiles the registry during the build and loads a self-hashed artifact; it does
not scan handlers on every startup.

## Contract

- Query/Request execute immediately and have exactly one handler.
- Command is always durable and returns `CommandReceipt` after `staged` or
  `accepted`, not after handler completion.
- Event attempts every subscription and throws `PublicationFailed` with the
  complete `PublicationReceipt` on partial failure.
- The PostgreSQL adapter uses database time, `SKIP LOCKED`, and fenced lease CAS.
- The production worker is a PCNTL parent with a fresh child/runtime per attempt.
- Only `@api` symbols listed in the
  [generated reference](docs/en/reference/public-api.md) are public. `Internal`
  is not an extension contract.

Operational tasks live in the [English documentation](docs/en); the Russian
edition starts at [README.md](README.md). The stable-v6 transition is documented
in the [migration guide](docs/en/migration/v6-to-v7.md); there are intentionally
no compatibility shims for Envelope, Flow, or queue internals.

## Agent integration (opt-in)

The package ships `$integrate-message-bus`, which first inspects the host
application and produces a minimal configuration map. It is never activated
automatically:

```bash
composer require --dev llm/skills
composer skills:init --quick
composer skills:update romanfedorskij/message-bus
```

## Development

```bash
composer test
composer test:process
composer analyse
composer cs:check
composer rector:check
composer api:check
composer docs:check
```

PostgreSQL integration and the durable example require a disposable database.
Release, migration, and consumer-rehearsal actions remain separate and are never
triggered by package bootstrap.

License: [MIT](LICENSE).
