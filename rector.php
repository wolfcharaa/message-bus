<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->withPhpSets()
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        typeDeclarations: true,
    )
    ->withSkip([
        Rector\DeadCode\Rector\ClassMethod\RemoveUnusedPublicMethodParameterRector::class,
        Rector\TypeDeclaration\Rector\ClassMethod\ReturnNeverTypeRector::class,
        // MBR008 needs a deliberately missing native return type; keep that invalid fixture intact.
        Rector\DeadCode\Rector\ClassMethod\RemoveUselessReturnTagRector::class => [
            __DIR__ . '/tests/RegistryDiagnosticsTest.php',
        ],
        Rector\TypeDeclaration\Rector\ClassMethod\ReturnTypeFromStrictConstantReturnRector::class => [
            __DIR__ . '/tests/RegistryDiagnosticsTest.php',
        ],
        Rector\TypeDeclaration\Rector\ClassMethod\StringReturnTypeFromStrictStringReturnsRector::class => [
            __DIR__ . '/tests/RegistryDiagnosticsTest.php',
        ],
    ])
    ->withoutParallel()
    ->withCache(__DIR__ . '/var/rector');
