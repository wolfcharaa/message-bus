---
doc_id: guides.handler-middleware
contract_revision: 1
---

# Handler middleware

`HandlerMiddleware<TResult>` оборачивает одну handler attempt; `NextHandler` можно вызвать не более одного раза.

- Middleware может short-circuit result или вызвать continuation один раз.

- Один pipeline используется для inline и worker execution.

- Delivery retry, acceptance и operator control не являются handler middleware concerns.

Точные signatures перечислены в [публичном API](../reference/public-api.md); при конфликте source manifest является нормативным.
