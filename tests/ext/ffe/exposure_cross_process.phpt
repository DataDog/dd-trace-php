--TEST--
FFE exposures that repeat in one process reach the sidecar, which deduplicates across processes
--SKIPIF--
<?php
if (!extension_loaded('pcntl')) die('skip: pcntl extension required');
if (PHP_VERSION_ID < 70200) die('skip: TEST_PHP_EXTRA_ARGS requires PHP 7.2+');
if (!function_exists('proc_open') || !function_exists('stream_socket_server')) die('skip: process and stream sockets required');
if (!getenv('TEST_PHP_EXECUTABLE')) die('skip: TEST_PHP_EXECUTABLE required');
?>
--ENV--
DD_INSTRUMENTATION_TELEMETRY_ENABLED=0
DD_REMOTE_CONFIG_ENABLED=0
DD_METRICS_OTEL_ENABLED=0
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_CLI_ENABLED=1
DD_EXPERIMENTAL_FLAGGING_PROVIDER_ENABLED=true
DD_TRACE_AGENT_TIMEOUT=5000
DD_TRACE_SIDECAR_CONNECTION_MODE=subprocess
DD_SERVICE=php-ffe-exposure-cross-process
--FILE--
<?php
require __DIR__ . '/stubs/exposure_capture.inc';
$rows = ffeCaptureExposures(__DIR__ . '/stubs/exposure_cross_process_app.php', array('first:', 'other_worker:'), 3);
foreach ($rows as $row) {
    if ($row['flag']['key'] !== 'flag' || $row['subject']['id'] !== 'user') throw new RuntimeException('unexpected exposure identity');
}
echo 'exposures=', json_encode(array_column(array_column($rows, 'allocation'), 'key')), "\n";
?>
--EXPECT--
first: handed_off=true
other_worker: handed_off=true
back: handed_off=true
repeat: handed_off=true
exposures=["allocation-a","allocation-b","allocation-a"]
