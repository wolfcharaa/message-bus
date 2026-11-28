<?php

declare(strict_types=1);

namespace MessageBusExample\ComposerDiscovery;

use Wolfcharaa\MessageBus\Attribute\Handler;
use Wolfcharaa\MessageBus\Contract\Query;
use Wolfcharaa\MessageBus\Registry\ComposerDirectoryClassSource;
use Wolfcharaa\MessageBus\Registry\RegistryCompiler;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

/** @implements Query<string> */
final readonly class Greeting implements Query
{
    public function __construct(public string $name) {}
}

final readonly class GreetingHandler
{
    #[Handler]
    public function __invoke(Greeting $query): string
    {
        return 'Hello ' . $query->name;
    }
}

$source = new ComposerDirectoryClassSource(
    [__DIR__],
    [__NAMESPACE__ . '\\'],
);
$registry = (new RegistryCompiler())->compile($source)->requireRegistry();

echo count($registry->bindings()) . ' message, '
    . count(iterator_to_array($source->classes(), false)) . " declarations\n";
