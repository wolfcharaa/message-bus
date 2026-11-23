<?php

declare(strict_types=1);

require dirname(__DIR__) . '/autoload.php';
require dirname(__DIR__) . '/support.php';

echo MessageBusExamples\run('idempotency'), PHP_EOL;
