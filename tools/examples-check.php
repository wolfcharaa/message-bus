<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$expected = [
    'orchestration' => 'Hello Ada',
    'custom-context' => 'example',
    'middleware' => 'HELLO ADA',
    'codec-upcast' => 'ada@example.test',
    'idempotency' => 'executed,replayed,1',
    'observer' => 'dispatch_started,dispatch_completed',
];

foreach ($expected as $scenario => $output) {
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/examples/' . $scenario . '/run.php');
    exec($command, $lines, $code);
    $actual = implode("\n", $lines);
    if ($code !== 0 || $actual !== $output) {
        fwrite(STDERR, sprintf("Example %s failed: code=%d output=%s\n", $scenario, $code, $actual));
        exit(1);
    }
    $lines = [];
}

foreach ([
    $root . '/examples/durable-postgres/run.php',
    $root . '/resources/conformance/run-durable-backend.php',
    $root . '/resources/conformance/run-idempotency-boundary.php',
] as $executable) {
    $lint = [];
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($executable), $lint, $code);
    if ($code !== 0) {
        fwrite(STDERR, implode("\n", $lint) . PHP_EOL);
        exit(1);
    }
}

fwrite(STDOUT, sprintf("All %d offline examples pass; durable PostgreSQL syntax is valid.\n", count($expected)));
