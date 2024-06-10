--TEST--
Sidecar should be enabled by default on PHP 8.3
--SKIPIF--
<?php include 'startup_logging_skip_unless_unix_83.inc'; ?>
--FILE--
<?php
include_once 'startup_logging.inc';

// This test isn't relevant now as we're not enabling sidecar on 8.3.
// I'll leave it here as a commodity.

// IN PHP 8.3, the sidecar is enabled by default, let's test this here
// In all other versions the sidecar is disabled but this is tested by sidecar_disabled_when_telemetry_disabled.phpt
$logs = dd_get_startup_logs([], [
    'DD_TRACE_DEBUG' => '1',
]);

dd_dump_startup_logs($logs, [
    'sidecar_trace_sender',
]);

?>
--EXPECT--
sidecar_trace_sender: false
