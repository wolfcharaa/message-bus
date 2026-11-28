# MessageBus

[Русская версия](README.md)

A typed PHP 8.3+ message bus with a deterministic registry and optional durable
delivery through PostgreSQL and PCNTL. The library exposes one
`MessageBus::dispatch()` method for Query, Request, Command, and Event. The host
application retains DI, business transactions, deployment, and authorization.

## Installation

```bash
composer require romanfedorskij/message-bus:^7.0@beta
```

The durable profile requires `ext-pdo_pgsql`, `ext-pcntl`, and `ext-posix`.
Symfony Console, PSR-11, and PSR-3 are only needed by their adapters; core
requires PHP, JSON, and `psr/clock`.

## Choose a path

- [Orchestration without a queue](docs/en/getting-started/orchestration.md) —
  Query/Request and inline Event in the caller process.
- [Durable PostgreSQL](docs/en/getting-started/durable-postgres.md) — Command,
  durable Event, ready `PostgresRuntime` composition, delivery status/control,
  and worker processes.
- [Composer-based handler discovery](docs/en/getting-started/composer-directory-discovery.md) —
  a quick start without a manual class list, safe development refreshes, and a
  production artifact without filesystem scanning.

After installation, verify the minimal composition with the packaged example:

```bash
php vendor/romanfedorskij/message-bus/examples/orchestration/run.php
# Hello Ada
```

The application-facing call looks like this; the complete composition is linked
below:

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

For automatic production PSR-4/classmap declaration discovery, install the
optional build/development parser:

```bash
composer require --dev composer/class-map-generator:^1.8
```

It is not needed in production after the artifact is built.

In a PSR-11 framework, pass the container through
`PsrContainerHandlerServiceResolver`; original autowiring exceptions are not
replaced by a library error. Standard durable codecs can be built with
`MessageCodecs::fromRegistry($registry)`, and MessageBus commands can be added
to an existing Symfony `Application` through the `Console::register*()` methods.

## Contract

- Query/Request execute immediately and have exactly one handler.
- Command is always durable and returns `CommandReceipt` after `staged` or
  `accepted`, not after handler completion. A target independent of the PHP
  class is declared with `#[Handler(bindingId: 'command.name')]`.
- Event attempts every subscription and throws `PublicationFailed` with the
  complete `PublicationReceipt` on partial failure.
- The PostgreSQL adapter uses database time, `SKIP LOCKED`, and fenced lease CAS.
- The production worker is a PCNTL parent with a fresh child/runtime per attempt.
- Only `@api` symbols listed in the
  [generated reference](docs/en/reference/public-api.md) are public. `Internal`
  is not an extension contract.
- When something fails, follow the
  [diagnostic troubleshooting guide](docs/en/guides/troubleshooting-diagnostics.md),
  then look up the exact `MBR`, `MBC`, `MBD`, or `MBCLI` code in the
  [diagnostics reference](docs/en/reference/diagnostics.md).

The capability map and next tasks live in the
[English documentation](docs/en/reference/capability-map.md); the Russian
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
composer skills:show romanfedorskij/message-bus
```

The last command must list `integrate-message-bus` with status `OK`. The plugin
copies the skill into the configured agent directory; do not edit the vendor
copy.

## Development

```bash
composer test
composer test:process
composer analyse
composer cs:check
composer rector:check
composer api:check
composer docs:check
composer skills:validate
```

PostgreSQL integration and the durable example require a disposable database.
Release, migration, and consumer-rehearsal actions remain separate and are never
triggered by package bootstrap.

License: [MIT](LICENSE).
