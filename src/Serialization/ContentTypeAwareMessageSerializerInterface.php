<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Serialization;

interface ContentTypeAwareMessageSerializerInterface extends MessageSerializerInterface
{
    /**
     * @return non-empty-list<non-empty-string>
     */
    public function supportedContentTypes(): array;

    public function supportsContentType(string $contentType): bool;
}
