<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;
use PHPyh\CodingStandard\PhpCsFixerCodingStandard;

$config = (new Config())
    ->setFinder(
        Finder::create()
            ->in(__DIR__ . '/bin')
            ->in(__DIR__ . '/src')
            ->in(__DIR__ . '/tests')
            ->append([__FILE__]),
    )
    ->setCacheFile(__DIR__ . '/var/' . basename(__FILE__) . '.cache');

(new PhpCsFixerCodingStandard())->applyTo($config);
$config->setRules([
    ...$config->getRules(),
    // Public contracts are intentionally grouped by capability in aggregate files.
    'psr_autoloading' => false,
]);

return $config;
