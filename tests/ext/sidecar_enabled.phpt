--TEST--
The sidecar trace sender is enabled by default
--SKIPIF--
<?php include 'startup_logging_skipif.inc'; ?>
--FILE--
<?php
include_once 'startup_logging.inc';

$logs = dd_get_startup_logs([], [
    'DD_TRACE_DEBUG' => '1',
]);

dd_dump_startup_logs($logs, [
    'sidecar_trace_sender',
]);

?>
--EXPECT--
sidecar_trace_sender: true
