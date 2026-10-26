--TEST--
FFE flush from the main thread includes worker-only evaluations
--SKIPIF--
<?php
if (!PHP_ZTS || !extension_loaded('parallel')) die('skip: ZTS and parallel extension required');
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
DD_SERVICE=php-ffe-threads
--FILE--
<?php
$modes = array('worker', 'explicit', 'internal', 'shutdown');
$flushApp = __DIR__ . '/stubs/flag_evaluation_threads_app.php';
require __DIR__ . '/stubs/flag_evaluation_flush.inc';
?>
--EXPECT--
false/worker: bool(true)
false/explicit: bool(true)
false/internal: bool(true)
false/shutdown: bool(true)
true/worker: bool(true)
true/explicit: bool(true)
true/internal: bool(true)
true/shutdown: bool(true)
