# Registry compilation and diagnostics

`registry:compile` can discover handlers, validate the registry, print diagnostics, and write the compiled PHP artifact in one command.

## Bootstrap

Create a PHP bootstrap that returns `RegistryCompileInput`:

```php
<?php

declare(strict_types=1);

use Wolfcharaa\MessageBus\Cli\RegistryCompileInput;
use Wolfcharaa\MessageBus\Discovery\ComposerClassMapProvider;
use Wolfcharaa\MessageBus\Registry\DeprecationDiagnosticsMode;
use Wolfcharaa\MessageBus\Registry\MessageRegistryCompilerOptions;

return new RegistryCompileInput(
    provider: new ComposerClassMapProvider(
        classMapFile: __DIR__ . '/../vendor/composer/autoload_classmap.php',
        namespacePrefixes: ['App\\'],
    ),
    libraryVersion: '5.2.0',
    sourceHash: (string) getenv('APP_BUILD_HASH'),
    options: new MessageRegistryCompilerOptions(
        deprecations: DeprecationDiagnosticsMode::Warn,
    ),
);
```

Project-specific `RegistryValidationRuleInterface` implementations can be passed through `validationRules`. Flow configuration belongs in `flows`; diagnostic policy belongs in `options`.

The legacy bootstrap form returning `MessageRegistryDefinition` remains supported during the minor-version migration.

## Command

```bash
vendor/bin/message-bus registry:compile \
  --bootstrap=config/message_bus_registry.php \
  --output=var/cache/message_bus_registry.php
```

Useful options:

- `--explain` prints a focused `message -> handler -> flow` relation graph for each diagnostic;
- `--base-path=<path>` renders source files relative to a known project path;
- `--fail-on-warning` returns exit code `1` when any warning is emitted;
- `--deprecations=ignore|warn|fail` overrides the bootstrap deprecation policy.

The library default for deprecations is `ignore`, which keeps existing projects quiet. `warn` reports use of the legacy `Middleware` API without failing compilation. `fail` reports it as an error and does not write the registry.

Errors always return exit code `1` and prevent the output artifact from being written. Warnings return `0` unless `--fail-on-warning` is enabled.

`--explain` keeps the normal diagnostic summary and adds the compiler stage plus the affected relation graph. It does not dump the entire registry.
