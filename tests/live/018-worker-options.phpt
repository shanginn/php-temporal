--TEST--
Core\Worker constructor accepts tuning options and validates them
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
use function Async\spawn;
use function Async\await;

await(spawn(function () {
    $conn = new Connection(temporal_test_address());

    // Custom tuning goes through the full lifecycle.
    $worker = new Worker($conn, 'opts-' . bin2hex(random_bytes(3)), temporal_test_namespace(), 8, [
        'workflowSlots' => 16,
        'localActivitySlots' => 4,
        'nexusSlots' => 8,
        'maxCachedWorkflows' => 50,
        'stickyScheduleToStartTimeoutMs' => 5000,
        'gracefulShutdownMs' => 1000,
        'maxHeartbeatThrottleMs' => 2000,
        'maxActivitiesPerSecond' => 4.5,
        'maxTaskQueueActivitiesPerSecond' => 3.5,
        'activityPollers' => 2,
        'workflowPollers' => 2,
        'nexusPollers' => 1,
        'maxEagerActivityReservationsPerWorkflowTask' => 4,
        'enableNexus' => true,
        'identity' => 'php-test-worker',
        'buildId' => 'test-build',
    ]);
    $worker->initiateShutdown();
    $worker->pollWorkflowActivation();
    $worker->pollActivityTask();
    $worker->pollNexusTask();
    $worker->finalizeShutdown();
    var_dump('lifecycle ok');

    // Structured slot suppliers and poller behavior map onto Core's native
    // resource tuner/autoscaler. Flat limits are bounded fallbacks.
    $resourceSupplier = static fn (
        int $minimum,
        int $maximum,
        int $rampThrottleMs,
    ): array => [
        'type' => 'resourceBased',
        'minimumSlots' => $minimum,
        'maximumSlots' => $maximum,
        'rampThrottleMs' => $rampThrottleMs,
        'targetMemoryUsage' => 0.75,
        'targetCpuUsage' => 0.85,
    ];
    $tuned = new Worker(
        $conn,
        'tuned-' . bin2hex(random_bytes(3)),
        temporal_test_namespace(),
        20,
        [
            'workflowSlots' => 20,
            'localActivitySlots' => 20,
            'nexusSlots' => 20,
            'activitySlotSupplier' => $resourceSupplier(1, 20, 50),
            'workflowSlotSupplier' => $resourceSupplier(5, 20, 0),
            'localActivitySlotSupplier' => $resourceSupplier(1, 20, 50),
            'nexusSlotSupplier' => $resourceSupplier(1, 20, 50),
            'activityPollers' => 8,
            'workflowPollers' => 8,
            'nexusPollers' => 8,
            'activityPollerBehavior' => [
                'type' => 'autoscaling',
                'minimum' => 1,
                'maximum' => 8,
                'initial' => 2,
            ],
            'workflowPollerBehavior' => [
                'type' => 'autoscaling',
                'minimum' => 1,
                'maximum' => 8,
                'initial' => 2,
            ],
            'nexusPollerBehavior' => [
                'type' => 'simpleMaximum',
                'maximum' => 3,
            ],
            'enableNexus' => true,
        ],
    );
    $tuned->initiateShutdown();
    $tuned->pollWorkflowActivation();
    $tuned->pollActivityTask();
    $tuned->pollNexusTask();
    $tuned->finalizeShutdown();
    var_dump('tuned lifecycle ok');

    $activityOnly = new Worker(
        $conn,
        'activity-only-' . bin2hex(random_bytes(3)),
        temporal_test_namespace(),
        8,
        ['disableWorkflows' => true],
    );
    $activityOnly->initiateShutdown();
    $activityOnly->pollActivityTask();
    $activityOnly->finalizeShutdown();
    var_dump('activity only ok');

    // Out-of-range and mistyped values are rejected before the core sees them.
    try {
        new Worker($conn, 'opts-bad', temporal_test_namespace(), 8, ['workflowSlots' => 0]);
    } catch (ValueError $e) {
        var_dump($e->getMessage());
    }
    try {
        new Worker($conn, 'opts-bad', temporal_test_namespace(), 8, ['activityPollers' => 'many']);
    } catch (ValueError $e) {
        var_dump($e->getMessage());
    }
    try {
        new Worker($conn, 'opts-bad', temporal_test_namespace(), 0);
    } catch (ValueError $e) {
        var_dump($e->getMessage());
    }
    try {
        new Worker($conn, 'opts-bad', temporal_test_namespace(), 8, ['versioningStrategy' => 1]);
    } catch (ValueError $e) {
        var_dump($e->getMessage());
    }
    try {
        new Worker($conn, 'opts-bad', temporal_test_namespace(), 8, ['versioningBehavior' => 3]);
    } catch (ValueError $e) {
        var_dump($e->getMessage());
    }
    try {
        new Worker($conn, 'opts-bad', temporal_test_namespace(), 8, [
            'workflowSlotSupplier' => [
                'type' => 'resourceBased',
                'minimumSlots' => 10,
                'maximumSlots' => 9,
                'rampThrottleMs' => 0,
                'targetMemoryUsage' => 0.75,
                'targetCpuUsage' => 0.85,
            ],
        ]);
    } catch (ValueError $e) {
        var_dump($e->getMessage());
    }
    try {
        new Worker($conn, 'opts-bad', temporal_test_namespace(), 8, [
            'activitySlotSupplier' => [
                'type' => 'resourceBased',
                'minimumSlots' => 1,
                'maximumSlots' => 10,
                'rampThrottleMs' => 50,
                'targetMemoryUsage' => 0.0,
                'targetCpuUsage' => 0.85,
            ],
        ]);
    } catch (ValueError $e) {
        var_dump($e->getMessage());
    }
    try {
        new Worker($conn, 'opts-bad', temporal_test_namespace(), 8, [
            'workflowPollerBehavior' => [
                'type' => 'simpleMaximum',
                'maximum' => 1,
            ],
        ]);
    } catch (ValueError $e) {
        var_dump($e->getMessage());
    }
    try {
        new Worker($conn, 'opts-bad', temporal_test_namespace(), 8, [
            'activityPollerBehavior' => [
                'type' => 'autoscaling',
                'minimum' => 2,
                'maximum' => 5,
                'initial' => 6,
            ],
        ]);
    } catch (ValueError $e) {
        var_dump($e->getMessage());
    }
}));
?>
--EXPECT--
string(12) "lifecycle ok"
string(18) "tuned lifecycle ok"
string(16) "activity only ok"
string(73) "Worker option 'workflowSlots' must be an integer between 1 and 4294967295"
string(75) "Worker option 'activityPollers' must be an integer between 1 and 4294967295"
string(57) "maxConcurrentActivities must be a positive 32-bit integer"
string(67) "Deployment versioning requires non-empty deploymentName and buildId"
string(69) "Worker option 'versioningBehavior' must be an integer between 0 and 2"
string(95) "Worker option 'workflowSlotSupplier.maximumSlots' must be greater than or equal to minimumSlots"
string(100) "Worker option 'activitySlotSupplier.targetMemoryUsage' must be a number greater than 0 and at most 1"
string(90) "Worker option 'workflowPollerBehavior.maximum' must be an integer between 2 and 4294967295"
string(82) "Worker option 'activityPollerBehavior.initial' must be between minimum and maximum"
