---
doc_id: concepts.workers-and-processes
contract_revision: 2
---

# Workers и процессы

Production worker — один PCNTL parent supervisor и fresh child на каждую attempt; concurrency=1 покрывает последовательный режим.

- Parent claims, heartbeat-ит lease, следит за control state и reaps child PIDs.

- Child после fork выполняет все `ForkResourceReset`, затем `ChildRuntimeBuilder` создаёт fresh PDO/container scope через `ResettingChildExecutionFactory`; выполняется ровно один handler.

- `WorkerProcessSystem` изолирует PCNTL/POSIX calls для проверки fork failure, TERM/KILL и reap. Profile поддерживает bounded recycle по attempts/runtime/memory и `stopWhenEmpty`.

- SIGTERM/SIGINT прекращает claims и запускает bounded drain; exit code не заменяет persisted settlement.

- External supervisor обязан завершать всю process group при неожиданной смерти parent.

Точные signatures перечислены в [публичном API](../reference/public-api.md); при конфликте source manifest является нормативным.
