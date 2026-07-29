--TEST--
Core\Worker offline replay stream closes and shuts down without a server
--EXTENSIONS--
temporal
--FILE--
<?php
use TrueAsync\Temporal\Core\Worker;
use function Async\spawn;
use function Async\await;

$result = await(spawn(function () {
    $worker = Worker::createReplay();

    // Closing an empty replay stream tells Core that no more histories will
    // arrive. The operation is deliberately idempotent for cleanup paths.
    $worker->closeReplayHistory();
    $worker->closeReplayHistory();

    $activation = $worker->pollWorkflowActivation();
    $worker->finalizeShutdown();

    return $activation;
}));

var_dump($result === null);
?>
--EXPECT--
bool(true)
