--TEST--
Transport classes and exception hierarchy are registered
--EXTENSIONS--
temporal
--FILE--
<?php
use TrueAsync\Temporal\Core\Connection;
use TrueAsync\Temporal\Core\Worker;
use TrueAsync\Temporal\TemporalException;
use TrueAsync\Temporal\ConnectionException;
use TrueAsync\Temporal\ServiceException;

var_dump(class_exists(Connection::class));
var_dump(class_exists(Worker::class));
var_dump((new ReflectionMethod(Worker::class, 'createReplay'))->isStatic());
var_dump(method_exists(Worker::class, 'pushReplayHistory'));
var_dump(method_exists(Worker::class, 'closeReplayHistory'));
var_dump(method_exists(Worker::class, 'pollNexusTask'));
var_dump(method_exists(Worker::class, 'completeNexusTask'));
var_dump(is_subclass_of(TemporalException::class, RuntimeException::class));
var_dump(is_subclass_of(ConnectionException::class, TemporalException::class));
var_dump(is_subclass_of(ServiceException::class, TemporalException::class));
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
