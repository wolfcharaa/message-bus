# Worker control plane

Worker control plane нужен для эксплуатации long-running workers.

Он отвечает не за выполнение бизнес-handler-а, а за управление worker runtime:

- показать живые master/child processes;
- поставить worker pool на pause;
- вернуть worker pool через resume;
- сделать graceful drain перед deploy;
- остановить workers;
- force kill зависшие child processes;
- graceful restart через exit code для supervisor/docker/systemd;
- показать audit команд управления;
- показать acknowledgements, какие worker-ы применили command.

## Архитектура

Control plane имеет несколько входов:

- CLI command;
- HTTP controller;
- admin UI action;
- framework console command;
- maintenance script.

Все входы должны использовать один service:

```php
use Wolfcharaa\MessageBus\Worker\WorkerControlServiceInterface;
use Wolfcharaa\MessageBus\Worker\WorkerTarget;

$control->pause(new WorkerTarget(workerGroup: 'emails'), reason: 'maintenance');
$control->drain(new WorkerTarget(workerGroup: 'reports'), reason: 'deploy');
$control->restart(new WorkerTarget(workerName: 'emails-worker'), reason: 'config reload');
```

CLI является thin wrapper над этим service.

## PostgreSQL tables

Worker control PostgreSQL schema состоит из пяти таблиц:

- `message_bus__worker_commands` - append-only command log.
- `message_bus__worker_desired_states` - active desired state для `pause/resume`.
- `message_bus__worker_instances` - materialized state master worker-ов.
- `message_bus__worker_child_instances` - materialized state child processes.
- `message_bus__worker_command_acknowledgements` - какой worker применил какую command.

Сгенерировать только worker control schema:

```bash
vendor/bin/message-bus worker:schema:postgres
```

Сгенерировать все таблицы библиотеки:

```bash
vendor/bin/message-bus schema:postgres --with=all
```

## Worker identity

Worker instance имеет identity:

- `workerName` - человекочитаемое имя worker-а.
- `workerInstanceId` - уникальный id конкретного запуска.
- `workerGroup` - pool/group.
- `host` - host/container.
- `pid` - PID master process.
- `mode` - `single` или `auto`.
- `transport` и `queue`.
- `flows`, `bindingIds`, `bindingPatterns`.

CLI пример:

```bash
vendor/bin/message-bus worker:run \
  --bootstrap=config/message_bus_runtime.php \
  --mode=auto \
  --workers=4 \
  --worker-name=emails-worker \
  --worker-group=emails \
  --worker-instance-id=emails-app-01-1 \
  --host=app-01
```

Если `worker-instance-id` не передан, auto runner генерирует его сам.

## Targeting

Control commands поддерживают фильтры:

- `--worker-id`;
- `--worker-name`;
- `--worker-instance-id`;
- `--group`;
- `--transport`;
- `--queue`;
- `--flow`;
- `--binding-id`;
- `--binding-pattern`;
- `--mode`;
- `--host`;
- `--all`.

Global command требует явный `--all`.

Примеры:

```bash
vendor/bin/message-bus worker:pause --bootstrap=config/message_bus_runtime.php --group=emails
vendor/bin/message-bus worker:drain --bootstrap=config/message_bus_runtime.php --queue=reports
vendor/bin/message-bus worker:stop --bootstrap=config/message_bus_runtime.php --worker-instance-id=emails-app-01-1
vendor/bin/message-bus worker:kill --bootstrap=config/message_bus_runtime.php --all
```

## Commands

Worker control команды запускаются через тот же `--bootstrap`, что и worker. Bootstrap нужен, чтобы CLI получил `MessageBusRuntime`, PostgreSQL connection и `WorkerControlRuntime`.

Перед любой управляющей командой полезно посмотреть состояние:

```bash
vendor/bin/message-bus worker:status \
  --bootstrap=config/message_bus_runtime.php \
  --children
```

Практический выбор команды:

| Команда | Когда запускать | Что произойдёт |
| --- | --- | --- |
| `worker:status` | Нужно понять, какие workers живы и что они делают | Покажет master workers, lifecycle state, activity, heartbeat и, с `--children`, running child processes |
| `worker:pause` | Нужно временно остановить взятие новых задач без остановки процесса | Worker перестанет брать новые jobs, но уже running children продолжат выполнение |
| `worker:resume` | Нужно вернуть paused workers в работу | Worker снова начнёт брать jobs |
| `worker:drain` | Нужно подготовить deploy/rebalance без потери running jobs | Worker перестанет брать новые jobs, дождётся running children и завершится |
| `worker:stop` | Нужно штатно остановить worker | Worker выполнит graceful stop: не берёт новые jobs, ждёт running children, выходит |
| `worker:kill` | Есть зависшие children или нужно аварийно освободить runtime | Master отправит children `SIGTERM`, после timeout - `SIGKILL` |
| `worker:restart` | Нужно перезапустить worker через supervisor/docker/systemd | Worker сделает graceful drain и выйдет с restart exit code |
| `worker:control` | Нужна универсальная команда для UI/API/scripts | Отправляет любую control command с полным набором metadata |

## `worker:status`

Используйте как первую диагностическую команду.

```bash
vendor/bin/message-bus worker:status \
  --bootstrap=config/message_bus_runtime.php
```

С children:

```bash
vendor/bin/message-bus worker:status \
  --bootstrap=config/message_bus_runtime.php \
  --children
```

С фильтром по группе:

```bash
vendor/bin/message-bus worker:status \
  --bootstrap=config/message_bus_runtime.php \
  --group=emails \
  --children
```

Что смотреть:

- `state=running` - worker работает.
- `state=paused` - worker живой, но не берёт новые jobs.
- `state=draining` - worker заканчивает running children и должен выйти.
- `state=stopping` - worker штатно останавливается.
- `state=killing` - worker выполняет аварийное завершение children.
- `heartbeat_at` - если давно не обновлялся, worker или child может быть зависшим.
- `children_count` - сколько child processes сейчас выполняют jobs.

## `worker:pause`

`pause` нужен, когда worker pool должен остаться живым, но временно не должен брать новые jobs.

Типовые случаи:

- maintenance window;
- временно остановить тяжёлую queue;
- дать database/API отдышаться;
- остановить обработку конкретной группы workers, не трогая остальные.

```bash
vendor/bin/message-bus worker:pause \
  --bootstrap=config/message_bus_runtime.php \
  --group=emails \
  --reason="maintenance"
```

Важно:

- `pause` не убивает process.
- `pause` не отменяет running jobs.
- Running children продолжают работу.
- Новые jobs остаются в `pending`.
- Это desired state: новые matching workers тоже увидят pause и не начнут брать jobs.

## `worker:resume`

`resume` возвращает paused workers в работу.

```bash
vendor/bin/message-bus worker:resume \
  --bootstrap=config/message_bus_runtime.php \
  --group=emails \
  --reason="maintenance finished"
```

Используйте `resume`, когда:

- maintenance закончился;
- нагрузку можно вернуть;
- нужно снять ранее установленный pause desired state.

## `worker:drain`

`drain` нужен для безопасного вывода worker-а из работы.

```bash
vendor/bin/message-bus worker:drain \
  --bootstrap=config/message_bus_runtime.php \
  --group=reports \
  --reason="deploy"
```

Что делает `drain`:

- master перестаёт брать новые jobs;
- running children продолжают работу;
- master ждёт завершения children;
- после завершения running jobs worker выходит.

Используйте `drain` перед deploy, rolling restart, изменением worker limits или переносом нагрузки между queues.

## `worker:stop`

`stop` - штатная остановка worker-а.

```bash
vendor/bin/message-bus worker:stop \
  --bootstrap=config/message_bus_runtime.php \
  --worker-instance-id=emails-app-01-1 \
  --reason="manual stop"
```

Практическая разница с `drain`:

- `drain` обычно используют как deploy/rebalance semantics.
- `stop` используют как явную operator-команду остановить конкретный worker.
- Оба пути graceful: running children не должны быть убиты.

Если нужно остановить весь pool:

```bash
vendor/bin/message-bus worker:stop \
  --bootstrap=config/message_bus_runtime.php \
  --group=emails
```

## `worker:kill`

`kill` - аварийная команда.

```bash
vendor/bin/message-bus worker:kill \
  --bootstrap=config/message_bus_runtime.php \
  --worker-instance-id=emails-app-01-1 \
  --reason="stuck child"
```

Что делает auto runner:

- переводит lifecycle в `killing`;
- отправляет running children `SIGTERM`;
- ждёт `--force-kill-timeout`;
- если child не завершился, отправляет `SIGKILL`;
- завершает worker после остановки children.

Используйте `kill`, когда:

- child завис и не обновляет heartbeat;
- graceful stop/drain не завершился;
- процесс удерживает внешний lock/resource;
- нужно аварийно освободить worker pool.

Не используйте `kill` как обычный deploy path. Для deploy лучше `drain` или `restart`.

## `worker:restart`

`restart` нужен, когда worker должен корректно завершить текущую работу и быть поднят заново внешним supervisor-ом.

```bash
vendor/bin/message-bus worker:restart \
  --bootstrap=config/message_bus_runtime.php \
  --worker-name=emails-worker \
  --reason="config reload"
```

Что делает `restart`:

- master перестаёт брать новые jobs;
- running children завершаются штатно;
- worker выходит с restart exit code;
- supervisor/docker/systemd поднимает новый process.

По умолчанию restart exit code задаётся опцией worker-а:

```bash
vendor/bin/message-bus worker:run \
  --bootstrap=config/message_bus_runtime.php \
  --mode=auto \
  --restart-exit-code=75
```

## `worker:control`

`worker:control` - универсальный entrypoint. Он нужен, если вы хотите строить admin UI/API/scripts вокруг одного command endpoint.

```bash
vendor/bin/message-bus worker:control \
  --bootstrap=config/message_bus_runtime.php \
  --command=restart \
  --group=emails \
  --created-by=root \
  --source=cli \
  --reason="deploy"
```

Через него можно отправить:

- `pause`;
- `resume`;
- `drain`;
- `stop`;
- `kill`;
- `restart`.

Metadata:

- `--created-by` - кто отправил команду.
- `--source` - откуда пришла команда: `cli`, `ui`, `api`, `script`.
- `--reason` - человекочитаемая причина.
- `--request-id` - id внешнего request-а.
- `--correlation-id` - id цепочки операций.
- `--idempotency-key` - защита от повторной отправки одной и той же команды.
- `--expires-at` - срок жизни one-shot command.

## Примеры рабочих сценариев

Deploy worker pool без потери running jobs:

```bash
vendor/bin/message-bus worker:drain --bootstrap=config/message_bus_runtime.php --group=emails --reason="deploy"
vendor/bin/message-bus worker:status --bootstrap=config/message_bus_runtime.php --group=emails --children
```

Временно остановить обработку email jobs:

```bash
vendor/bin/message-bus worker:pause --bootstrap=config/message_bus_runtime.php --group=emails --reason="smtp outage"
vendor/bin/message-bus worker:resume --bootstrap=config/message_bus_runtime.php --group=emails --reason="smtp restored"
```

Перезапустить workers после изменения config:

```bash
vendor/bin/message-bus worker:restart --bootstrap=config/message_bus_runtime.php --worker-name=emails-worker --reason="config reload"
```

Аварийно остановить зависший worker:

```bash
vendor/bin/message-bus worker:status --bootstrap=config/message_bus_runtime.php --worker-instance-id=emails-app-01-1 --children
vendor/bin/message-bus worker:kill --bootstrap=config/message_bus_runtime.php --worker-instance-id=emails-app-01-1 --reason="heartbeat timeout"
```

## Handler context внутри worker-а

Во время выполнения queue job runner создаёт scoped `WorkerRuntimeControlInterface` для текущей задачи.

Default context использует этот control и даёт handler-ам два дополнительных контракта:

- `CancellableMessageContextInterface` - проверить или выбросить отмену.
- `HeartbeatAwareMessageContextInterface` - явно обновить heartbeat долгой операции.

```php
use Wolfcharaa\MessageBus\Context\CancellableMessageContextInterface;
use Wolfcharaa\MessageBus\Context\HeartbeatAwareMessageContextInterface;

final class GenerateReportHandler
{
    public function __invoke(GenerateReport $message, CancellableMessageContextInterface $context): void
    {
        foreach ($this->generator->pages($message->reportId) as $page) {
            $context->throwIfCancellationRequested();

            $this->renderer->render($page);

            if ($context instanceof HeartbeatAwareMessageContextInterface) {
                $context->heartbeat();
            }
        }
    }
}
```

Что происходит под капотом:

- `QueueWorkerRunner` создаёт control для текущего `queueMessageId`.
- `PcntlAutoWorkerRunner` создаёт control для `queueMessageId` и `childInstanceId`.
- `MessageBus` передаёт этот control в `MessageContextFactoryInterface`.
- `DefaultMessageContext` вызывает `QueueJobControlInterface::isCancellationRequested()`.
- `DefaultMessageContext::heartbeat()` обновляет queue heartbeat и, в auto mode, child heartbeat.

Если вы пишете custom context factory, передайте четвёртый аргумент `?WorkerRuntimeControlInterface` в свой context или используйте его внутри factory.

## Status

```bash
vendor/bin/message-bus worker:status --bootstrap=config/message_bus_runtime.php
vendor/bin/message-bus worker:status --bootstrap=config/message_bus_runtime.php --group=emails --children
```

Status показывает:

- worker instance id;
- name/group/host/pid;
- mode;
- lifecycle state;
- activity;
- children count;
- transport/queue;
- heartbeat time.

Child status показывает:

- child instance id;
- pid;
- state;
- queueMessageId;
- messageId;
- correlationId;
- bindingId;
- heartbeat time.

## Pause/resume desired state

`pause/resume` отличаются от one-shot commands.

`pause` создаёт desired state для target-а. Новый worker при старте проверит desired states и сразу перейдёт в paused, если matching target.

`resume` снимает или переопределяет pause по тем же targeting rules.

Если несколько desired states подходят worker-у:

- побеждает более конкретный target;
- при одинаковой специфичности побеждает более свежий state;
- `resume --override` может явно переопределить менее конкретный pause.

## One-shot commands

`drain`, `stop`, `kill`, `restart` являются one-shot commands.

У них есть TTL через `expiresAt`. Если worker увидел команду слишком поздно, она не применяется.

Default TTL:

- `kill` - 60 секунд;
- `drain`, `stop`, `restart` - 300 секунд.

## Auto worker integration

В `--mode=auto` master process:

- регистрирует себя в worker registry;
- пишет heartbeat;
- читает control commands не чаще `--control-poll-interval`;
- применяет desired pause/resume state;
- регистрирует child при fork;
- обновляет child lifecycle при reap;
- heartbeat-ит children из master loop;
- применяет `drain/stop/kill/restart`.

Полезные параметры:

```bash
vendor/bin/message-bus worker:run \
  --mode=auto \
  --workers=4 \
  --control-poll-interval=1000 \
  --heartbeat-interval=1000 \
  --force-kill-timeout=5 \
  --restart-exit-code=75
```

`restart` не делает self-exec по умолчанию. Master завершает работу с restart exit code, а supervisor/docker/systemd/application wrapper должен поднять новый процесс.
