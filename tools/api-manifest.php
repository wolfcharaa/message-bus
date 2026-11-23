<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$classMap = require $root . '/vendor/composer/autoload_classmap.php';
$symbols = [];
$errors = [];

foreach (array_keys($classMap) as $name) {
    if (!str_starts_with($name, 'Wolfcharaa\\MessageBus\\')
        || str_starts_with($name, 'Wolfcharaa\\MessageBus\\Tests\\')) {
        continue;
    }

    $reflection = reflect($name);
    if (!$reflection instanceof ReflectionClass || !$reflection->isUserDefined()) {
        continue;
    }

    $filename = $reflection->getFileName();
    if (!is_string($filename) || !str_starts_with($filename, $root . '/src/')) {
        continue;
    }

    $documentation = $reflection->getDocComment() ?: '';
    $internal = str_contains($name, '\\Internal\\');
    if ($internal) {
        if (!str_contains($documentation, '@internal')) {
            $errors[] = $name . ' is in Internal but lacks @internal.';
        }
        continue;
    }

    if (!preg_match('/@api\s+(application|spi|adapter|tooling)\b/', $documentation, $matches)) {
        $errors[] = $name . ' is public but lacks @api application|spi|adapter|tooling.';
        continue;
    }

    $symbols[] = symbol($reflection, $matches[1], $documentation);
}

usort($symbols, static fn(array $left, array $right): int => $left['name'] <=> $right['name']);
$manifest = [
    'schema' => 'message-bus-public-api/v1',
    'package' => 'romanfedorskij/message-bus',
    'php' => '^8.3',
    'symbols' => $symbols,
];

if ($errors !== []) {
    fwrite(STDERR, implode(PHP_EOL, $errors) . PHP_EOL);
    exit(1);
}

$json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
$english = reference($manifest, 'en');
$russian = reference($manifest, 'ru');
$targets = [
    $root . '/resources/public-api.json' => $json,
    $root . '/docs/en/reference/public-api.md' => $english,
    $root . '/docs/ru/reference/public-api.md' => $russian,
];

if (($argv[1] ?? '--check') === '--write') {
    foreach ($targets as $path => $contents) {
        if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0775, true) && !is_dir(dirname($path))) {
            throw new RuntimeException('Cannot create ' . dirname($path));
        }
        file_put_contents($path, $contents);
    }
    fwrite(STDOUT, sprintf("Wrote %d public symbols.\n", count($symbols)));
    exit(0);
}

foreach ($targets as $path => $expected) {
    $actual = is_file($path) ? file_get_contents($path) : false;
    if ($actual !== $expected) {
        fwrite(STDERR, $path . " is stale; run composer api:write.\n");
        exit(1);
    }
}

fwrite(STDOUT, sprintf("Public API manifest is current (%d symbols).\n", count($symbols)));

/** @return ReflectionClass<object>|null */
function reflect(string $name): ?ReflectionClass
{
    if (!class_exists($name) && !interface_exists($name) && !trait_exists($name) && !enum_exists($name)) {
        return null;
    }

    return new ReflectionClass($name);
}

/** @return array<string, mixed> */
function symbol(ReflectionClass $reflection, string $role, string $documentation): array
{
    $methods = [];
    foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        if ($method->getDeclaringClass()->getName() !== $reflection->getName()) {
            continue;
        }
        $methods[] = methodSignature($method);
    }
    sort($methods);

    $properties = [];
    foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
        if ($property->getDeclaringClass()->getName() !== $reflection->getName()) {
            continue;
        }
        $properties[] = sprintf(
            '%s%s $%s',
            $property->isReadonly() ? 'readonly ' : '',
            type($property->getType(), $property->getDeclaringClass()),
            $property->getName(),
        );
    }
    sort($properties);

    $constants = [];
    foreach ($reflection->getReflectionConstants(ReflectionClassConstant::IS_PUBLIC) as $constant) {
        if ($constant->getDeclaringClass()->getName() !== $reflection->getName() || $constant->isEnumCase()) {
            continue;
        }
        $constants[] = $constant->getName();
    }
    sort($constants);

    $cases = $reflection->isEnum()
        ? array_map(static fn(UnitEnum $case): string => $case->name, $reflection->getMethod('cases')->invoke(null))
        : [];

    $kind = match (true) {
        $reflection->isInterface() => 'interface',
        $reflection->isTrait() => 'trait',
        $reflection->isEnum() => 'enum',
        default => 'class',
    };

    $parent = $reflection->getParentClass();

    return [
        'name' => $reflection->getName(),
        'kind' => $kind,
        'role' => $role,
        'summary' => summary($documentation),
        'final' => $reflection->isFinal(),
        'readonly' => $reflection->isReadOnly(),
        'parent' => $parent instanceof ReflectionClass ? $parent->getName() : null,
        'interfaces' => array_values($reflection->getInterfaceNames()),
        'methods' => $methods,
        'properties' => $properties,
        'constants' => $constants,
        'enum_cases' => $cases,
    ];
}

function methodSignature(ReflectionMethod $method): string
{
    $declaringClass = $method->getDeclaringClass();
    $parameters = [];
    foreach ($method->getParameters() as $parameter) {
        $part = type($parameter->getType(), $declaringClass) . ' ';
        if ($parameter->isPassedByReference()) {
            $part .= '&';
        }
        if ($parameter->isVariadic()) {
            $part .= '...';
        }
        $part .= '$' . $parameter->getName();
        if ($parameter->isDefaultValueAvailable()) {
            $part .= ' = ' . defaultValue($parameter);
        }
        $parameters[] = $part;
    }

    return sprintf(
        '%s%s(%s): %s',
        $method->isStatic() ? 'static ' : '',
        $method->getName(),
        implode(', ', $parameters),
        type($method->getReturnType(), $declaringClass),
    );
}

function type(?ReflectionType $type, ?ReflectionClass $declaringClass = null): string
{
    if ($type === null) {
        return 'mixed';
    }
    if ($type instanceof ReflectionUnionType) {
        return implode('|', array_map(
            static fn(ReflectionType $part): string => type($part, $declaringClass),
            $type->getTypes(),
        ));
    }
    if ($type instanceof ReflectionIntersectionType) {
        return implode('&', array_map(
            static fn(ReflectionType $part): string => type($part, $declaringClass),
            $type->getTypes(),
        ));
    }

    $name = $type->getName();
    if ($name === 'self' && $declaringClass instanceof ReflectionClass) {
        $name = $declaringClass->getName();
    } elseif ($name === 'parent' && $declaringClass instanceof ReflectionClass) {
        $name = $declaringClass->getParentClass()?->getName() ?? $name;
    }
    $prefix = $type->allowsNull() && $name !== 'mixed' && $name !== 'null' ? '?' : '';

    return $prefix . $name;
}

function defaultValue(ReflectionParameter $parameter): string
{
    if ($parameter->isDefaultValueConstant()) {
        return (string) $parameter->getDefaultValueConstantName();
    }

    $value = $parameter->getDefaultValue();
    if (is_object($value)) {
        return 'new ' . $value::class;
    }

    return var_export($value, true);
}

function summary(string $documentation): string
{
    $lines = preg_split('/\R/', $documentation) ?: [];
    foreach ($lines as $line) {
        $line = trim($line, " \t\n\r\0\x0B/*");
        if ($line !== '' && !str_starts_with($line, '@')) {
            return $line;
        }
    }

    return '';
}

/** @param array<string, mixed> $manifest */
function reference(array $manifest, string $locale): string
{
    $ru = $locale === 'ru';
    $title = $ru ? 'Публичный PHP API' : 'Public PHP API';
    $intro = $ru
        ? 'Сгенерировано из PHPDoc `@api`; не редактируйте вручную. Роли: application, spi, adapter, tooling.'
        : 'Generated from PHPDoc `@api`; do not edit manually. Roles: application, spi, adapter, tooling.';
    $lines = [
        '---',
        'doc_id: reference.public-api',
        'contract_revision: 1',
        '---',
        '',
        '# ' . $title,
        '',
        $intro,
        '',
    ];

    foreach (['application', 'spi', 'adapter', 'tooling'] as $role) {
        $lines[] = '## ' . ucfirst($role);
        $lines[] = '';
        foreach ($manifest['symbols'] as $symbol) {
            if ($symbol['role'] !== $role) {
                continue;
            }
            $lines[] = '### `' . $symbol['name'] . '`';
            $lines[] = '';
            $lines[] = $symbol['summary'];
            $lines[] = '';
            $lines[] = '- ' . ($ru ? 'Вид' : 'Kind') . ': `' . $symbol['kind'] . '`';
            foreach ($symbol['methods'] as $method) {
                $lines[] = '- `' . str_replace('`', '\\`', $method) . '`';
            }
            foreach ($symbol['enum_cases'] as $case) {
                $lines[] = '- `case ' . $case . '`';
            }
            $lines[] = '';
        }
    }

    return implode(PHP_EOL, $lines) . PHP_EOL;
}
