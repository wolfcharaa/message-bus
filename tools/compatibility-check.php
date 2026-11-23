<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Wolfcharaa\MessageBus\Delivery\DeliveryState;
use Wolfcharaa\MessageBus\Delivery\FailureCategory;
use Wolfcharaa\MessageBus\Observability\RuntimeEventType;
use Wolfcharaa\MessageBus\Registry\MessageRole;

/** @return array<string, mixed> */
function document(string $name): array
{
    $path = __DIR__ . '/../resources/compatibility/' . $name . '.json';
    $value = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    if (!\is_array($value) || array_is_list($value) || ($value['schema'] ?? null) !== 1) {
        throw new RuntimeException('Invalid compatibility catalog: ' . $name);
    }

    return $value;
}

/** @param list<string> $actual @param list<string> $expected */
function sameValues(string $name, array $actual, array $expected): void
{
    sort($actual, SORT_STRING);
    sort($expected, SORT_STRING);
    if ($actual !== $expected) {
        throw new RuntimeException($name . ' compatibility catalog is stale.');
    }
}

$events = document('runtime-events');
$eventDefinitions = $events['events'] ?? null;
if (!\is_array($eventDefinitions) || array_is_list($eventDefinitions)) {
    throw new RuntimeException('Runtime event definitions must be an object.');
}
sameValues(
    'Runtime event',
    array_map(static fn(RuntimeEventType $case): string => $case->value, RuntimeEventType::cases()),
    array_values(array_filter(array_keys($eventDefinitions), 'is_string')),
);
foreach (RuntimeEventType::cases() as $type) {
    $definition = $eventDefinitions[$type->value] ?? null;
    if (!\is_array($definition) || ($definition['version'] ?? null) !== 1
        || ($definition['severity'] ?? null) !== $type->severity()->value) {
        throw new RuntimeException('Runtime event schema or severity catalog is stale: ' . $type->value);
    }
}

$wire = document('wire');
$roles = $wire['message_roles'] ?? null;
$states = $wire['delivery_states'] ?? null;
$categories = $wire['failure_categories'] ?? null;
if (!\is_array($roles) || !array_is_list($roles) || !\is_array($states) || !array_is_list($states)
    || !\is_array($categories) || !array_is_list($categories)) {
    throw new RuntimeException('Wire compatibility lists are invalid.');
}
sameValues('Message role', array_map(static fn(MessageRole $case): string => $case->value, MessageRole::cases()), $roles);
sameValues('Delivery state', array_map(static fn(DeliveryState $case): string => $case->value, DeliveryState::cases()), $states);
sameValues(
    'Failure category',
    array_map(static fn(FailureCategory $case): string => $case->value, FailureCategory::cases()),
    $categories,
);

$diagnostics = document('diagnostics');
$expectedCodes = $diagnostics['codes'] ?? null;
$registrySource = (string) file_get_contents(__DIR__ . '/../src/Registry.php')
    . (string) file_get_contents(__DIR__ . '/../src/Routing.php');
preg_match_all("/'(MB[RC][0-9]{3})'/", $registrySource, $matches);
$actualCodes = array_values(array_unique($matches[1] ?? []));
if (!\is_array($expectedCodes) || !array_is_list($expectedCodes)) {
    throw new RuntimeException('Diagnostic compatibility list is invalid.');
}
sameValues('Diagnostic', $actualCodes, $expectedCodes);

$cli = document('cli');
$expectedCommands = $cli['commands'] ?? null;
$consoleSource = (string) file_get_contents(__DIR__ . '/../src/InternalConsoleCommands.php');
preg_match_all("/(?:setName|configureMutation)\('([^']+)'/", $consoleSource, $matches);
$actualCommands = array_values(array_unique($matches[1] ?? []));
if (!\is_array($expectedCommands) || array_is_list($expectedCommands)) {
    throw new RuntimeException('CLI compatibility definitions must be an object.');
}
sameValues('CLI', $actualCommands, array_values(array_filter(array_keys($expectedCommands), 'is_string')));

fwrite(STDOUT, sprintf(
    "Compatibility catalogs are current: %d events, %d diagnostics, %d commands.\n",
    count($eventDefinitions),
    count($expectedCodes),
    count($expectedCommands),
));
