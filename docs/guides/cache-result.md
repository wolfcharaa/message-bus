# Cache result

Handler result можно кешировать через `#[CacheResult]` и `MessageCacheMiddleware`.

```php
#[CacheResult(ttlSeconds: 60, identityKey: 'user-report', varyHeaders: ['tenant'])]
#[QueryHandler(message: BuildReportQuery::class, bindingId: 'report.build')]
final class BuildReportHandler
{
    public function __invoke(BuildReportQuery $query, MessageContextInterface $context): ReportDto
    {
        // expensive query
    }
}
```

Подробный пример: [CacheResult middleware](docs/examples/07-cache-result.md).

По умолчанию cache result использует JSON serializer. Для PHP-only result DTO можно подключить PHP serializer:

```php
use Wolfcharaa\MessageBus\Cache\PhpSerializeResultSerializer;
use Wolfcharaa\MessageBus\Middleware\MessageCacheMiddleware;

$middleware = new MessageCacheMiddleware(
    cache: $cache,
    policies: $policyRegistry,
    serializer: new PhpSerializeResultSerializer(allowedClasses: true),
);
```

Если надо не кешировать часть результатов, реализуйте `CacheResultPolicyInterface` и передайте его в middleware.
