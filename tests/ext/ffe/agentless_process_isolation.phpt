--TEST--
FFE agentless configuration HTTP runs outside the PHP application process
--SKIPIF--
<?php
if (PHP_VERSION_ID < 70200) die('skip: TEST_PHP_EXTRA_ARGS requires PHP 7.2+');
if (PHP_OS !== 'Linux' || !is_readable('/proc/self/net/tcp')) die('skip: Linux socket ownership required');
if (!function_exists('proc_open') || !function_exists('stream_socket_server')) die('skip: process and sockets required');
if (!getenv('TEST_PHP_EXECUTABLE')) die('skip: TEST_PHP_EXECUTABLE required');
?>
--ENV--
DD_FEATURE_FLAGS_ENABLED=1
DD_FEATURE_FLAGS_CONFIGURATION_SOURCE=agentless
DD_EXPERIMENTAL_FLAGGING_PROVIDER_INITIALIZATION_TIMEOUT_MS=10000
DD_TRACE_ENABLED=0
DD_TRACE_CLI_ENABLED=1
DD_REMOTE_CONFIG_ENABLED=0
DD_INSTRUMENTATION_TELEMETRY_ENABLED=0
DD_METRICS_OTEL_ENABLED=0
DD_TRACE_AGENT_URL=http://127.0.0.1:1
NO_PROXY=127.0.0.1,localhost
--FILE--
<?php
$scenario = 'isolation';
require __DIR__ . '/stubs/agentless_process.inc';
?>
--EXPECT--
cdn_configuration_evaluated
cdn_request_outside_php
