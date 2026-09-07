<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Registry;

interface RegistryValidationRuleInterface
{
    /** @return iterable<RegistryDiagnostic> */
    public function validate(RegistryCompilationGraphContext $context): iterable;
}
