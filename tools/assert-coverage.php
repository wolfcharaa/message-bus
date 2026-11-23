<?php

declare(strict_types=1);

if ($argc < 3 || $argc > 4) {
    \fwrite(STDERR, "Usage: php tools/assert-coverage.php <clover.xml> <min-line-percent> [min-class-percent]\n");
    exit(2);
}

$file = $argv[1];
$minimumLine = (float) $argv[2];
$minimumClass = $argc === 4 ? (float) $argv[3] : null;

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

$linePercent = ($coveredStatements / $statements) * 100;
if ($linePercent + 0.00001 < $minimumLine) {
    \fwrite(STDERR, \sprintf(
        "Line coverage %.2f%% is below required %.2f%% (%d/%d statements).\n",
        $linePercent,
        $minimumLine,
        $coveredStatements,
        $statements,
    ));
    exit(1);
}

\fwrite(STDOUT, \sprintf(
    "Line coverage %.2f%% meets required %.2f%% (%d/%d statements).\n",
    $linePercent,
    $minimumLine,
    $coveredStatements,
    $statements,
));

if ($minimumClass === null) {
    exit(0);
}

$classes = 0;
$coveredClasses = 0;
foreach ($coverage->project->xpath('.//class') ?: [] as $classNode) {
    $classMetrics = $classNode->metrics;
    if (!$classMetrics instanceof SimpleXMLElement) {
        continue;
    }

    $classMethods = (int) ($classMetrics['methods'] ?? 0);
    if ($classMethods <= 0) {
        continue;
    }
    ++$classes;

    $coveredMethods = (int) ($classMetrics['coveredmethods'] ?? 0);
    $classStatements = (int) ($classMetrics['statements'] ?? 0);
    $coveredStatements = (int) ($classMetrics['coveredstatements'] ?? 0);

    if ($coveredMethods === $classMethods && $coveredStatements === $classStatements) {
        ++$coveredClasses;
    }
}

if ($classes <= 0) {
    \fwrite(STDERR, "Coverage report contains no executable classes.\n");
    exit(2);
}

$classPercent = ($coveredClasses / $classes) * 100;
if ($classPercent + 0.00001 < $minimumClass) {
    \fwrite(STDERR, \sprintf(
        "Class coverage %.2f%% is below required %.2f%% (%d/%d classes).\n",
        $classPercent,
        $minimumClass,
        $coveredClasses,
        $classes,
    ));
    exit(1);
}

\fwrite(STDOUT, \sprintf(
    "Class coverage %.2f%% meets required %.2f%% (%d/%d classes).\n",
    $classPercent,
    $minimumClass,
    $coveredClasses,
    $classes,
));
