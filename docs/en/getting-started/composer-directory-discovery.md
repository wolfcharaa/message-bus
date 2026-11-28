---
doc_id: getting-started.composer-directory-discovery
contract_revision: 1
---

# Composer-based handler discovery

`ComposerDirectoryClassSource` gives projects a quick start without a manually
maintained message/handler class list. It uses `composer/class-map-generator` as
the PHP declaration parser, scans only bounded roots, and gives the compiler the
exact source path for every class.

## Installation

The dependency is needed while building the registry and in development, but
not by a production runtime which loads a precompiled artifact:

```bash
composer require --dev composer/class-map-generator:^1.8
```

If the runtime image uses `composer install --no-dev`, compile the artifact in a
build stage with development dependencies first, then copy it into the runtime
image.

## Option 1: production mappings from composer.json

Pass an absolute or application-owned path explicitly. The source does not
search for `composer.json` through the current working directory:

```php
use Wolfcharaa\MessageBus\Registry\ComposerDirectoryClassSource;

$source = ComposerDirectoryClassSource::fromComposerJson(
    __DIR__ . '/composer.json',
);
```

Only root `autoload.psr-4`, `autoload.classmap`, and
`autoload.exclude-from-classmap` are read. Neither `autoload-dev` nor
`autoload.files` becomes a discovery root, so test doubles and bootstrap files
cannot enter the production registry accidentally.

## Option 2: explicit directories

```php
$source = new ComposerDirectoryClassSource(
    directories: [__DIR__ . '/src/Message', __DIR__ . '/src/Handler'],
    namespacePrefixes: ['App\\Message\\', 'App\\Handler\\'],
    excludedDirectories: [__DIR__ . '/src/Handler/Legacy'],
);
```

The directories act as classmap roots and namespace prefixes bound the result.
Nested symlink directories are not followed. An explicitly mapped symlink root
is resolved through `realpath`, keeping its boundary explicit.

## Build and production startup

```php
use Wolfcharaa\MessageBus\Registry\RegistryArtifact;
use Wolfcharaa\MessageBus\Registry\RegistryArtifactLoader;
use Wolfcharaa\MessageBus\Registry\RegistryArtifactStore;
use Wolfcharaa\MessageBus\Registry\RegistryCompiler;

$artifactPath = __DIR__ . '/var/cache/message-bus-registry.json';
$buildId = $_SERVER['APP_BUILD_ID'] ?? null;

// Build stage: class-map-generator is installed.
$registry = (new RegistryCompiler())->compile($source)->requireRegistry();
(new RegistryArtifactStore())->write(
    RegistryArtifact::fromRegistry($registry, $buildId),
    $artifactPath,
);

// Production bootstrap: no filesystem discovery is performed.
$registry = (new RegistryArtifactLoader())->load(
    $artifactPath,
    applicationBuildId: $buildId,
);
```

`requireRegistry()` preserves the original scanner/autoload/attribute throwable
as `InvalidMessageBusConfiguration::getPrevious()`. The complete typed list
remains in `$exception->diagnostics`; a library error never replaces a DI or
autoload error with a generic message.

## Automatic refresh in development only

```php
use Wolfcharaa\MessageBus\Registry\RegistryArtifactLoader;
use Wolfcharaa\MessageBus\Registry\RegistryRefreshPolicy;

$isDev = ($_SERVER['APP_ENV'] ?? 'prod') === 'dev';
$registry = (new RegistryArtifactLoader())->load(
    artifactPath: $artifactPath,
    policy: $isDev
        ? RegistryRefreshPolicy::WhenStale
        : RegistryRefreshPolicy::PrecompiledOnly,
    source: $isDev ? $source : null,
    applicationBuildId: $buildId,
);
```

At bootstrap, `WhenStale` hashes configuration and PHP resources. A change
causes it to acquire a cross-process lock, recheck freshness, compile, and
atomically replace the artifact and `.meta.json`. A failed rebuild aborts
bootstrap; it never silently returns the old artifact. No checks run on each
`dispatch()`. Restart long-running workers through the application's normal
mechanism.

`PrecompiledOnly` rejects a source, making the absence of production scanning a
testable guarantee. The artifact directory must exist and be writable only for
build/development refresh; a production loader only needs the deployed file.

## Validations

- duplicate declarations and paths which differ only by letter case;
- exact production PSR-4 namespace/path correspondence;
- missing or invalid composer.json and mappings;
- scanner/parser failures with the original throwable preserved;
- an empty scan as a warning;
- every message and handler declaration, including multiple classes per file.

See the [diagnostics reference](../reference/diagnostics.md) for `MBR021`–`MBR028`
and their corrections. The minimal executable example is
`examples/composer-discovery/run.php`.
