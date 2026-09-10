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
