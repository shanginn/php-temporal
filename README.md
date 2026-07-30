<div align="center">

# php-temporal

**Native asynchronous [Temporal](https://temporal.io/) transport for [PHP TrueAsync](https://github.com/true-async).**
*Write sync, run async.*

[![PHP](https://img.shields.io/badge/PHP-8.x_ZTS-777BB4?style=flat-square&logo=php&logoColor=white)](https://www.php.net/)
[![PHP TrueAsync](https://img.shields.io/badge/runtime-PHP_TrueAsync-4F5B93?style=flat-square&logo=php&logoColor=white)](https://github.com/true-async/php-async)
[![Temporal](https://img.shields.io/badge/Temporal-Rust_Core-000000?style=flat-square&logo=temporal&logoColor=white)](https://github.com/temporalio/sdk-rust)
[![License](https://img.shields.io/badge/license-Apache_2.0-blue?style=flat-square)](LICENSE)

</div>

A thin native extension that runs the **official Temporal Rust Core**
([`temporalio/sdk-rust`](https://github.com/temporalio/sdk-rust)) in-process and
exposes it to PHP as an async transport. No gRPC extension, no RoadRunner: the
core's gRPC runs on its own Tokio threads, and completions are delivered back to
the TrueAsync reactor through a cross-thread trigger, so every call looks
synchronous while the coroutine yields underneath.

The **high-level client API is the reused official Temporal PHP SDK** (the
`Temporal\*` namespace), driven through a `ServiceClientInterface` adapter over
this transport — see the [`shanginn/sdk-php`](https://github.com/shanginn/sdk-php)
fork (branch `true-async`), which strips gRPC/RoadRunner from the dependencies.

A client starting a workflow — **written flat**: the first Core call launches the
TrueAsync scheduler and the script runs as the main coroutine, so no explicit
`Async\spawn()` wrapper is needed (the same way `Async\await()` does it).

```php
use Temporal\Client\WorkflowClient;
use Temporal\Client\WorkflowOptions;
use Temporal\Client\GRPC\TrueAsyncServiceClient;
use TrueAsync\Temporal\Core\Connection;

$client = WorkflowClient::create(
    TrueAsyncServiceClient::fromCore(new Connection('127.0.0.1:7233')),
);

$stub = $client->newUntypedWorkflowStub('OrderWorkflow',
    (new WorkflowOptions())->withTaskQueue('orders')->withWorkflowId('order-42'));

$run = $client->start($stub, $orderId);
echo $run->getResult();   // parks the coroutine until the workflow completes
```

More — defining workflows/activities, running a worker, signals and queries — in
[Usage](#usage) below.

## What this extension provides

Just the transport seam:

- `TrueAsync\Temporal\Core\Connection` — `connect` plus an async
  `rpcCall(service, method, requestBytes): responseBytes`.
- `TrueAsync\Temporal\Core\Worker` — the worker transport: poll/complete for
  activity tasks, workflow activations, and Nexus tasks; activity heartbeat
  recording; and the shutdown lifecycle. It also exposes an offline replay
  worker that accepts serialized `Temporal\Api\History\V1\History` messages, so
  the reused SDK can verify workflow histories without a server.
- `TrueAsync\Temporal\{TemporalException, ConnectionException, ServiceException}`.

Everything user-facing (workflow client, options, data converter, the generated
`Temporal\Api\*` protobuf messages) comes from the reused SDK.

## Usage

Workflow and activity code is the **reused SDK's** — the same attributes and
generator (`yield`) style as `temporalio/sdk-php`; only the transport differs.

### Define a workflow and an activity

```php
use Temporal\Activity\ActivityInterface;
use Temporal\Activity\ActivityMethod;
use Temporal\Activity\ActivityOptions;
use Temporal\Workflow;
use Temporal\Workflow\WorkflowInterface;
use Temporal\Workflow\WorkflowMethod;

#[WorkflowInterface]
class OrderWorkflow
{
    #[WorkflowMethod(name: 'OrderWorkflow')]
    public function run(string $orderId): \Generator
    {
        $activities = Workflow::newActivityStub(
            OrderActivities::class,
            ActivityOptions::new()->withStartToCloseTimeout(10),
        );

        $charged = yield $activities->charge($orderId);   // schedule + await the activity

        return "order {$orderId}: {$charged}";
    }
}

#[ActivityInterface(prefix: 'Order.')]
class OrderActivities
{
    #[ActivityMethod]
    public function charge(string $orderId): string
    {
        // real I/O is fine here — activities run in ordinary TrueAsync coroutines
        return 'charged';
    }
}
```

### Run a worker

```php
use Temporal\Worker\TrueAsync\TemporalWorker;
use TrueAsync\Temporal\Core\Connection;
use TrueAsync\Temporal\Core\Worker as CoreWorker;

$core = new CoreWorker(new Connection('127.0.0.1:7233'), 'orders');  // 'orders' = task queue

(new TemporalWorker($core, 'orders'))
    ->registerWorkflowTypes(OrderWorkflow::class)
    ->registerActivityImplementations(new OrderActivities())
    ->run();   // long-polls workflows + activities until shutdown()
```

`run()` blocks until the core shuts down; call `$worker->shutdown()` from a signal
handler or another coroutine to stop the loops, after which `run()` finalizes and
returns.

### Serve and call Nexus operations

The high-level SDK registers Nexus services on an ordinary native Worker. It
enables the Core Nexus task type only when that Worker has at least one service:

```php
use Temporal\Client\GRPC\ServiceClient;
use Temporal\Client\WorkflowClient;
use Temporal\WorkerFactory;

$client = WorkflowClient::create(ServiceClient::create('127.0.0.1:7233'));
$factory = WorkerFactory::create(client: $client);
$factory->newWorker('nexus-handler')
    ->registerWorkflowTypes(BackingWorkflow::class)
    ->registerNexusServiceImplementation(new PaymentsService());
$factory->run();
```

The extension exposes the underlying `pollNexusTask()` and
`completeNexusTask()` methods for the SDK transport loop. Applications should
use `WorkerFactory` and the public `Temporal\Nexus` API rather than encode Core
protobuf messages directly. See the
[SDK Nexus guide](https://github.com/shanginn/sdk-php/blob/true-async/docs/nexus.md)
for service contracts, Endpoints, callers, cancellation, failures, and
timeouts.

### Replay a workflow history

Replay uses the same workflow activation/completion path as a live worker, but
the Core worker is created without a server connection. The high-level SDK's
`WorkflowReplayer` drives this API; extension integrations can use the low-level
transport directly:

```php
use TrueAsync\Temporal\Core\Worker as CoreWorker;

$replay = CoreWorker::createReplay();
$replay->pushReplayHistory($workflowId, $history->serializeToString());
$replay->closeReplayHistory();

while (($activation = $replay->pollWorkflowActivation()) !== null) {
    $replay->completeWorkflowActivation(handleActivation($activation));
}
$replay->finalizeShutdown();
```

`pushReplayHistory()` accepts a serialized
`Temporal\Api\History\V1\History`. Close the input stream after the final
history; Core then shuts the replay worker down after all queued histories have
finished.

### Signal and query a running workflow

Signal and query handlers are plain methods on the workflow:

```php
#[WorkflowInterface]
class SubscriptionWorkflow
{
    private bool $cancelled = false;

    #[WorkflowMethod(name: 'SubscriptionWorkflow')]
    public function run(): \Generator
    {
        yield Workflow::await(fn() => $this->cancelled);
        return 'cancelled';
    }

    #[Workflow\SignalMethod(name: 'cancel')]
    public function cancel(): void
    {
        $this->cancelled = true;
    }

    #[Workflow\QueryMethod(name: 'isCancelled')]
    public function isCancelled(): bool
    {
        return $this->cancelled;
    }
}
```

Drive it from a client through the stub:

```php
$stub = $client->newUntypedWorkflowStub('SubscriptionWorkflow',
    (new WorkflowOptions())->withTaskQueue('orders')->withWorkflowId('sub-1'));
$run = $client->start($stub);

$open = (bool) $stub->query('isCancelled')->getValue(0);   // false — read state, no side effects
$stub->signal('cancel');                                    // deliver a signal
echo $run->getResult();                                     // "cancelled"
```

## Status

`0.1.0-dev` — pre-release, the API is not yet stable. Working end to end against
a live server today:

- **Transport** (`Core\Connection`, `Core\Worker`) — reviewed, ASAN-clean,
  covered by the test suite and CI.
- **Activity worker** — run, heartbeat, cooperative cancellation.
- **Nexus worker and caller** — synchronous and Workflow-backed operations,
  handler-method cancellation, typed failures, headers, links, all operation
  timeouts, and all cancellation modes through the native Core protocol.
- **Workflow worker** — the lifecycle through the reused SDK engine:
  start/complete, timers, activities (regular and local), signals, queries,
  cancellation (of the workflow, its timers, activities and child workflows),
  child workflows, continue-as-new, signalling and cancelling external/child
  workflows, updates (validate/accept/reject/complete), upserting search
  attributes (untyped and typed) and memo, panic, versioning
  (`Workflow::getVersion()` / patches and Worker Deployment Versioning), local
  activity retry/backoff, and surviving a reset (the reset run's new random
  seed is consumed and it re-executes cleanly).

Anything not yet mapped raises an explicit error rather than failing silently.

`Workflow::sideEffect()`, `uuid()`, `uuid4()`, and `uuid7()` remain available in
the TrueAsync SDK. Because Core exposes patch markers but no generic marker
command, the SDK records these values through an internal
`core_local_activity`; Core persists the result and supplies it during replay.
This is deterministic, but it deliberately produces different history from the
old RoadRunner `SideEffect` and `Version` markers. Drain or complete executions
that already contain those legacy markers before switching their task queue to
the TrueAsync worker, unless their histories have been separately replay-tested
against the new build.

## Why

The official [`temporalio/sdk-php`](https://github.com/temporalio/sdk-php) runs
PHP workers under **RoadRunner** with its own event loop, beside TrueAsync rather
than on it. This project links the **official Rust core** directly and reuses the
SDK's client layer on top, in-process on the TrueAsync reactor.

## Requirements

- PHP 8.x built with **ZTS** and the **TrueAsync** runtime.
- A Rust toolchain (`cargo`) to build the vendored `sdk-core-c-bridge`.
- The reused SDK package (`shanginn/sdk-php`, branch `true-async`), which pulls
  `google/protobuf` and the bundled Temporal protobuf messages.

## Build

```sh
git submodule update --init --recursive
cargo +1.94 build --release -p temporalio-sdk-core-c-bridge \
  --manifest-path third_party/sdk-rust/Cargo.toml      # build the Rust core bridge (once)
phpize && ./configure --enable-temporal --with-php-config="$(command -v php-config)"
make -j"$(nproc)"                                        # -> modules/temporal.so

php run-tests.php -q -p "$(command -v php)" \
  -d extension="$(pwd)/modules/temporal.so" tests/       # live/ cases SKIP without a dev server
```

Full instructions, requirements and the design rationale:
[docs/installation.md](docs/installation.md) and [docs/DESIGN.md](docs/DESIGN.md).

## License

Apache 2.0. Temporal and the Temporal logo are trademarks of Temporal
Technologies Inc.
