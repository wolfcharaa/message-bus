# CacheResult middleware

`MessageCacheMiddleware` is sync-only and explicit opt-in.

```php
use Wolfcharaa\MessageBus\Attribute\CacheResult;
use Wolfcharaa\MessageBus\Attribute\QueryHandler;

#[QueryHandler(message: FindUserMessage::class)]
#[CacheResult(ttlSeconds: 300, varyHeaders: ['app.tenant'])]
final class FindUserAction
{
    public function __invoke(FindUserMessage $message, MessageContextInterface $context): FindUserResult
    {
        // read model lookup
    }
}
```

Cache key is based on:

- message class/name;
- optional identity key;
- canonical portable message payload;
- allowlisted vary headers.

PHP `serialize()` is not used by the default cache serializer.
