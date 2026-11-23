<?php

declare(strict_types=1);

$candidates = [
    dirname(__DIR__, 2) . '/vendor/autoload.php',
    dirname(__DIR__, 4) . '/autoload.php',
];

foreach ($candidates as $autoload) {
    if (is_file($autoload)) {
        require $autoload;

        return;
    }
}

throw new RuntimeException('Composer autoloader was not found.');
