--TEST--
Core\Worker validates Nexus options and disabled Nexus polling without a server
--EXTENSIONS--
temporal
--FILE--
<?php
use TrueAsync\Temporal\Core\Worker;
use function Async\spawn;
use function Async\await;

try {
    Worker::createReplay(options: ['enableNexus' => 'yes']);
    echo "BUG: invalid enableNexus option accepted\n";
} catch (ValueError $e) {
    var_dump($e->getMessage());
}

// Replay Workers never enable Nexus. Polling a disabled task type must return
// shutdown instead of reaching an absent poller or aborting in Core.
$disabled = await(spawn(function () {
    $worker = Worker::createReplay();
    $nexus = $worker->pollNexusTask();
    $worker->closeReplayHistory();
    $worker->pollWorkflowActivation();
    $worker->finalizeShutdown();

    return $nexus;
}));

var_dump($disabled === null);
?>
--EXPECT--
string(45) "Worker option 'enableNexus' must be a boolean"
bool(true)
