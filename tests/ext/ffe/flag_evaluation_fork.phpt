--TEST--
FFE observations before and after fork use the existing subprocess-sidecar lifecycle without duplication
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
DD_SERVICE=php-ffe-fork
--FILE--
<?php
$modes = array('fork');
require __DIR__ . '/stubs/flag_evaluation_flush.inc';
?>
--EXPECT--
false/fork: bool(true)
true/fork: bool(true)
