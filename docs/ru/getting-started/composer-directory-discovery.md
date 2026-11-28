---
doc_id: getting-started.composer-directory-discovery
contract_revision: 1
---

# Автопоиск handlers через Composer

`ComposerDirectoryClassSource` даёт быстрый старт проекту, который не хочет
составлять список message/handler-классов вручную. Источник использует
`composer/class-map-generator` как PHP declaration parser, сканирует только
явно ограниченные корни и возвращает компилятору точный путь каждого класса.

## Установка

Dependency нужна при сборке реестра и в dev-окружении, но не нужна production
runtime, который загружает готовый artifact:

```bash
composer require --dev composer/class-map-generator:^1.8
```

Если production image собирается через `composer install --no-dev`, сначала
скомпилируйте artifact на build-стадии с dev dependencies, затем перенесите
artifact в runtime image.

## Вариант 1: production mappings из composer.json

Передавайте абсолютный либо известный приложению путь явно; источник не ищет
`composer.json` через текущий working directory:

```php
use Wolfcharaa\MessageBus\Registry\ComposerDirectoryClassSource;

$source = ComposerDirectoryClassSource::fromComposerJson(
    __DIR__ . '/composer.json',
);
```

Читаются только root `autoload.psr-4`, `autoload.classmap` и
`autoload.exclude-from-classmap`. `autoload-dev` и `autoload.files` не становятся
корнями discovery. Поэтому тестовые doubles и bootstrap files не попадают в
production registry случайно.

## Вариант 2: явные каталоги

```php
$source = new ComposerDirectoryClassSource(
    directories: [__DIR__ . '/src/Message', __DIR__ . '/src/Handler'],
    namespacePrefixes: ['App\\Message\\', 'App\\Handler\\'],
    excludedDirectories: [__DIR__ . '/src/Handler/Legacy'],
);
```

Здесь каталоги работают как classmap roots, а namespace prefixes ограничивают
результат. Вложенные symlink-каталоги не обходятся. Явно переданный symlink-root
разрешается через `realpath`, поэтому его граница остаётся известной.

## Build и production startup

```php
use Wolfcharaa\MessageBus\Registry\RegistryArtifact;
use Wolfcharaa\MessageBus\Registry\RegistryArtifactLoader;
use Wolfcharaa\MessageBus\Registry\RegistryArtifactStore;
use Wolfcharaa\MessageBus\Registry\RegistryCompiler;

$artifactPath = __DIR__ . '/var/cache/message-bus-registry.json';
$buildId = $_SERVER['APP_BUILD_ID'] ?? null;

// Build stage: class-map-generator установлен.
$registry = (new RegistryCompiler())->compile($source)->requireRegistry();
(new RegistryArtifactStore())->write(
    RegistryArtifact::fromRegistry($registry, $buildId),
    $artifactPath,
);

// Production bootstrap: filesystem discovery не выполняется.
$registry = (new RegistryArtifactLoader())->load(
    $artifactPath,
    applicationBuildId: $buildId,
);
```

`requireRegistry()` сохраняет исходный scanner/autoload/attribute throwable в
`InvalidMessageBusConfiguration::getPrevious()`. Полный типизированный список
остаётся в `$exception->diagnostics`; library error не заменяет ошибку DI или
autoloader общей формулировкой.

## Автопересборка только для dev

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

`WhenStale` на bootstrap хеширует configuration и PHP resources. При изменении
он берёт межпроцессный lock, повторно проверяет freshness, компилирует и атомарно
заменяет artifact и `.meta.json`. Ошибка пересборки прерывает bootstrap: старый
artifact молча не возвращается. Проверки не выполняются на каждом `dispatch()`.
Долгоживущий worker нужно перезапустить обычным механизмом приложения.

`PrecompiledOnly` не принимает source — это проверяемая гарантия отсутствия
production scan. Каталог artifact должен существовать и быть writable только
для build/dev refresh; production loader требует чтение готового файла.

## Что проверяется

- duplicate declarations и пути, различающиеся только регистром;
- точное соответствие production PSR-4 пути и namespace;
- отсутствующий/невалидный composer.json и mappings;
- ошибки scanner/parser с сохранением исходного throwable;
- пустой scan как warning;
- все message и handler declarations, включая несколько классов в одном файле.

Коды `MBR021`–`MBR028`, поля и исправления приведены в
[справочнике диагностик](../reference/diagnostics.md). Запускаемый минимальный
пример находится в `examples/composer-discovery/run.php`.
