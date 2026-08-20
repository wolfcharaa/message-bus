# Multi-binding action

Один action может быть общей реализацией для нескольких message contracts, если bindings объявлены явно.

```php
#[CommandHandler(
    message: ParseTuberculosisMmcDocumentMessage::class,
    flow: 'async',
    bindingId: 'reestr.import.parse_tuberculosis_mmc_document',
)]
#[CommandHandler(
    message: ParseTuberculosisMmcEscapeDocumentMessage::class,
    flow: 'async',
    bindingId: 'reestr.import.parse_tuberculosis_mmc_escape_document',
)]
final class ParseControlListDocumentAction
{
    public function __invoke(
        ParseTuberculosisMmcDocumentMessage|ParseTuberculosisMmcEscapeDocumentMessage $message,
        MessageContextInterface $context,
    ): void {
        // shared parser implementation
    }
}
```

Что важно:

- `bindingId` не вычисляется из FQCN автоматически;
- каждый async binding имеет стабильный публичный id;
- retry/reject/monitoring видят, какой конкретный document type упал;
- worker filters могут использовать exact ids или prefix/pattern, например `reestr.import.parse_*`.
