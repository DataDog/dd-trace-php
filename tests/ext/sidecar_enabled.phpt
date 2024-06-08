--TEST--
Sidecar should be enabled by default
--SKIPIF--
<?php include 'startup_logging_skipif.inc'; ?>
--FILE--
<?php
include_once 'startup_logging.inc';

// In all other versions the sidecar is disabled but this is tested by sidecar_disabled_when_telemetry_disabled.phpt
$logs = dd_get_startup_logs([], [
    'DD_TRACE_DEBUG' => '1',
]);

dd_dump_startup_logs($logs, [
    'sidecar_trace_sender',
]);

?>
--EXPECT--
sidecar_trace_sender: true
