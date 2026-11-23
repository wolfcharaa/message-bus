---
doc_id: concepts.workers-and-processes
contract_revision: 2
---

# Workers and processes

The production worker is one PCNTL parent supervisor with a fresh child per attempt; concurrency=1 is the serial mode.

- The parent claims work, heartbeats leases, observes control state, and reaps child PIDs.

- After fork, the child runs every `ForkResourceReset`; `ChildRuntimeBuilder` then creates a fresh PDO/container scope through `ResettingChildExecutionFactory`, and exactly one handler executes.

- `WorkerProcessSystem` isolates PCNTL/POSIX calls for fork-failure, TERM/KILL, and reap tests. A profile supports bounded recycling by attempts/runtime/memory and `stopWhenEmpty`.

- SIGTERM/SIGINT stop new claims and start bounded draining; an exit code never replaces persisted settlement.

- The external supervisor must terminate the whole process group if the parent dies unexpectedly.

Exact signatures are listed in the [public API](../reference/public-api.md); if they conflict, the source manifest is normative.
