<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Registry;

final class RegistryDiagnosticCodes
{
    public const ALIAS_DUPLICATE = 'registry.alias.duplicate';
    public const ALIAS_MULTIPLE_FOR_MESSAGE = 'registry.alias.multiple_for_message';
    public const ALIAS_MISSING_CLASS = 'registry.alias.missing_class';
    public const CACHE_BINDING_ID_REQUIRED = 'registry.cache.binding_id_required';
    public const BINDING_MISSING_ID = 'registry.binding.missing_id';
    public const BINDING_DUPLICATE_ID = 'registry.binding.duplicate_id';
    public const FLOW_MISSING = 'registry.flow.missing';
    public const FLOW_INVALID = 'registry.flow.invalid';
    public const HANDLER_INVALID_SIGNATURE = 'registry.handler.invalid_signature';
    public const INTERCEPTOR_INVALID_SIGNATURE = 'registry.interceptor.invalid_signature';
    public const MESSAGE_ALIAS_REQUIRED = 'registry.message.alias_required';
    public const QUERY_HANDLER_COUNT = 'registry.query.handler_count';
    public const QUERY_ASYNC_FLOW = 'registry.query.async_flow';
    public const MESSAGE_KIND_CONFLICT = 'registry.message.kind_conflict';
    public const COMMAND_PRIMARY_MISSING = 'registry.command.primary_missing';
    public const COMMAND_PRIMARY_DUPLICATE = 'registry.command.primary_duplicate';

    private function __construct()
    {
    }
}
