<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Dumper;

use Symfony\Component\VarExporter\VarExporter;
use Wolfcharaa\MessageBus\Registry\MessageRegistryDefinition;

final class SymfonyVarExporterRegistryDumper implements CompiledRegistryDumperInterface
{
    public function dump(MessageRegistryDefinition $definition): string
    {
        return "<?php\n\nreturn " . VarExporter::export($definition->toArray()) . ";\n";
    }
}
