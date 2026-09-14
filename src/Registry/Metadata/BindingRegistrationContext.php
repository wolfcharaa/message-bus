<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Registry\Metadata;

use LogicException;
use Wolfcharaa\MessageBus\Registry\HandlerBindingDefinition;

final readonly class BindingRegistrationContext
{
    public function __construct(
        public RegistryOwner $owner,
        public RegistrySource $source,
    ) {
    }

    public function stamp(HandlerBindingDefinition $binding, ?RegistrySource $source = null): HandlerBindingDefinition
    {
        if ($binding->owner !== null && !$binding->owner->equals($this->owner)) {
            throw new LogicException(\sprintf(
                'Binding `%s` already belongs to `%s:%s`, cannot stamp as `%s:%s`.',
                $binding->bindingId ?? $binding->action,
                $binding->owner->kind,
                $binding->owner->id,
                $this->owner->kind,
                $this->owner->id,
            ));
        }

        return $binding->withRegistrationMetadata($this->owner, $source ?? $binding->source ?? $this->source);
    }
}
