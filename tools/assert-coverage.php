<?php

declare(strict_types=1);

if ($argc !== 3) {
    \fwrite(STDERR, "Usage: php tools/assert-coverage.php <clover.xml> <min-line-percent>\n");
    exit(2);
}

$file = $argv[1];
$minimum = (float) $argv[2];

if (!\is_file($file)) {
    \fwrite(STDERR, \sprintf("Coverage file `%s` was not found.\n", $file));
    exit(2);
}

$coverage = \simplexml_load_file($file);
if (!$coverage instanceof SimpleXMLElement) {
    \fwrite(STDERR, \sprintf("Coverage file `%s` is not a valid XML document.\n", $file));
    exit(2);
}

$metrics = $coverage->project->metrics ?? $coverage->metrics;
if (!$metrics instanceof SimpleXMLElement) {
    \fwrite(STDERR, "Coverage metrics were not found in Clover report.\n");
    exit(2);
}

$statements = (int) ($metrics['statements'] ?? 0);
$coveredStatements = (int) ($metrics['coveredstatements'] ?? 0);
if ($statements <= 0) {
    \fwrite(STDERR, "Coverage report contains no statements.\n");
    exit(2);
}

$percent = ($coveredStatements / $statements) * 100;
if ($percent + 0.00001 < $minimum) {
    \fwrite(STDERR, \sprintf(
        "Line coverage %.2f%% is below required %.2f%% (%d/%d statements).\n",
        $percent,
        $minimum,
        $coveredStatements,
        $statements,
    ));
    exit(1);
}

\fwrite(STDOUT, \sprintf(
    "Line coverage %.2f%% meets required %.2f%% (%d/%d statements).\n",
    $percent,
    $minimum,
    $coveredStatements,
    $statements,
));
