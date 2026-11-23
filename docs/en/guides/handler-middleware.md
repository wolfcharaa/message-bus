---
doc_id: guides.handler-middleware
contract_revision: 1
---

# Handler middleware

`HandlerMiddleware<TResult>` wraps one handler attempt; `NextHandler` may be invoked at most once.

- Middleware may short-circuit the result or invoke the continuation once.

- The same pipeline is used for inline and worker execution.

- Delivery retry, acceptance, and operator control are not handler middleware concerns.

Exact signatures are listed in the [public API](../reference/public-api.md); if they conflict, the source manifest is normative.
