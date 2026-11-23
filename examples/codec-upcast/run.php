<?php

declare(strict_types=1);

require dirname(__DIR__) . '/autoload.php';
require dirname(__DIR__) . '/support.php';

echo MessageBusExamples\run('codec-upcast'), PHP_EOL;
