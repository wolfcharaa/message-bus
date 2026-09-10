# Registry compilation and diagnostics

`registry:compile` can discover handlers, validate the registry, print diagnostics, and write the compiled PHP artifact in one command.

## Bootstrap

Create a PHP bootstrap that returns `RegistryCompileInput`:

```php
<?php

declare(strict_types=1);

use Wolfcharaa\MessageBus\Cli\RegistryCompileInput;
use Wolfcharaa\MessageBus\Discovery\ComposerClassMapProvider;

return new RegistryCompileInput(
    provider: new ComposerClassMapProvider(
        classMapFile: __DIR__ . '/../vendor/composer/autoload_classmap.php',
        namespacePrefixes: ['App\\'],
    ),
    libraryVersion: '6.0.0',
    sourceHash: (string) getenv('APP_BUILD_HASH'),
);
```

Project-specific `RegistryValidationRuleInterface` implementations can be passed through `validationRules`. Flow configuration belongs in `flows`; diagnostic policy belongs in `options`.

The bootstrap must return `RegistryCompileInput` or `RegistryCompilationResult` so the CLI can render diagnostics and graph context.

## Command

```bash
vendor/bin/message-bus registry:compile \
  --bootstrap=config/message_bus_registry.php \
  --output=var/cache/message_bus_registry.php
```

Useful options:

- `--explain` prints a focused `message -> handler -> flow` relation graph for each diagnostic;
- `--base-path=<path>` renders source files relative to a known project path;
- `--fail-on-warning` returns exit code `1` when any warning is emitted.

Errors always return exit code `1` and prevent the output artifact from being written. Warnings return `0` unless `--fail-on-warning` is enabled.

`--explain` keeps the normal diagnostic summary and adds the compiler stage plus the affected relation graph. Failure output also prints a short hint to rerun with `--explain` when graph details are hidden.

## Runtime and worker cache safety

Treat the compiled registry file as a release artifact. It must be built from the same source revision and library version that will run producers and workers.

Recommended worker-safe rollout:

1. Build the project and run `registry:compile` during CI or release assembly.
2. Package or publish the generated registry file together with the application release.
3. Stop, drain, or pause old workers before switching the release.
4. Replace the old registry file atomically with the freshly compiled artifact.
5. Start producers and workers from the same release only after the registry file exists.
6. Keep async `MessageAlias` values and `bindingId` values stable while old queued jobs can still exist.

The runtime loader validates the registry schema on load. A v5 compiled file is rejected by v6 and must be rebuilt before workers start. When a project enables `requireCompiled`, a missing registry file is also a startup error; this is useful in production because it prevents workers from silently compiling a different local view of the code.

Queued jobs are serialized contracts. Before removing or renaming message classes, aliases, or async bindings, drain old queues or keep compatibility aliases and bindings until those jobs are processed.

If `MessageCacheMiddleware` is used, cache-result policies should target query bindings. Commands are execution rules in v6 and do not return business results through `dispatch()`.
