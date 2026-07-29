--TEST--
Core\Worker rejects malformed replay history and can still shut down cleanly
--EXTENSIONS--
temporal
--FILE--
<?php
use TrueAsync\Temporal\Core\Worker;
use TrueAsync\Temporal\TemporalException;
use function Async\spawn;
use function Async\await;

await(spawn(function () {
    $worker = Worker::createReplay();

    try {
        $worker->pushReplayHistory('workflow-id', "\xff");
        echo "BUG: malformed history accepted\n";
    } catch (TemporalException $e) {
        var_dump(str_contains($e->getMessage(), 'decode'));
    }

    $worker->closeReplayHistory();
    var_dump($worker->pollWorkflowActivation() === null);
    $worker->finalizeShutdown();
}));
?>
--EXPECT--
bool(true)
bool(true)
