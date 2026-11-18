--TEST--
FFE agentless events use the configured Unix socket
--SKIPIF--
<?php
if (PHP_OS === 'WINNT') die('skip: Unix sockets required');
if (PHP_VERSION_ID < 70200) die('skip: TEST_PHP_EXTRA_ARGS requires PHP 7.2+');
if (!function_exists('proc_open') || !function_exists('stream_socket_server')) die('skip: subprocess sockets required');
if (!getenv('TEST_PHP_EXECUTABLE')) die('skip: TEST_PHP_EXECUTABLE required');
?>
--ENV--
DD_TRACE_CLI_ENABLED=1
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_REMOTE_CONFIG_ENABLED=0
DD_INSTRUMENTATION_TELEMETRY_ENABLED=0
DD_METRICS_OTEL_ENABLED=0
DD_TRACE_AGENT_TIMEOUT=5000
DD_SERVICE=php-ffe-transport
DD_FLAGGING_EVALUATION_COUNTS_ENABLED=1
--FILE--
<?php
$modes = array('trace-agentless-unix');
require __DIR__ . '/stubs/agentless_evp_transport.inc';
?>
--EXPECT--
trace-agentless-unix/false: expected streams delivered with PHP identity, token and no API key
trace-agentless-unix/true: expected streams delivered with PHP identity, token and no API key
