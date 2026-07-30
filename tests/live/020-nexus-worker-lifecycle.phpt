--TEST--
Core\Worker Nexus completion, polling, shutdown, and finalized guards
--EXTENSIONS--
temporal
--SKIPIF--
<?php
require __DIR__ . '/../inc/temporal.inc';
temporal_skip_if_no_server();
?>
--FILE--
<?php
require __DIR__ . '/../inc/temporal.inc';

use TrueAsync\Temporal\Core\Connection;
use TrueAsync\Temporal\Core\Worker;
use TrueAsync\Temporal\ServiceException;
use function Async\spawn;
use function Async\await;

await(spawn(function () {
    $conn = new Connection(temporal_test_address());
    $worker = new Worker(
        $conn,
        'nexus-lifecycle-' . bin2hex(random_bytes(3)),
        temporal_test_namespace(),
        4,
        [
            'enableNexus' => true,
            'nexusSlots' => 4,
            'nexusPollers' => 1,
        ],
    );

    // NexusTaskCompletion { task_token: "abc", ack_cancel: true }. Core treats
    // a completion for an already-unknown token as an idempotent no-op.
    $validCompletion = "\x0a\x03abc\x20\x01";
    $worker->completeNexusTask($validCompletion);
    echo "completion: ok\n";

    try {
        $worker->completeNexusTask("\xff");
        echo "BUG: malformed Nexus completion accepted\n";
    } catch (ServiceException $e) {
        var_dump(str_contains($e->getMessage(), 'Nexus task decode failure'));
    }

    $worker->initiateShutdown();
    $workflow = $worker->pollWorkflowActivation();
    $activity = $worker->pollActivityTask();
    $nexus = $worker->pollNexusTask();
    $worker->finalizeShutdown();

    var_dump($workflow === null, $activity === null, $nexus === null);

    try {
        $worker->pollNexusTask();
        echo "BUG: Nexus poll after finalize did not throw\n";
    } catch (Error $e) {
        var_dump($e->getMessage());
    }

    try {
        $worker->completeNexusTask($validCompletion);
        echo "BUG: Nexus completion after finalize did not throw\n";
    } catch (Error $e) {
        var_dump($e->getMessage());
    }
}));
?>
--EXPECT--
completion: ok
bool(true)
bool(true)
bool(true)
bool(true)
string(45) "pollNexusTask must be called on a live worker"
string(49) "completeNexusTask must be called on a live worker"
