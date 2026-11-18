--TEST--
FFE agentless configuration reaches and refreshes the RC reader of every ZTS thread
--SKIPIF--
<?php
if (!PHP_ZTS || !extension_loaded('parallel')) die('skip: ZTS and parallel extension required');
if (!function_exists('proc_open') || !function_exists('stream_socket_server')) die('skip: process and stream sockets required');
if (!getenv('TEST_PHP_EXECUTABLE')) die('skip: TEST_PHP_EXECUTABLE required');
?>
--ENV--
DD_FEATURE_FLAGS_ENABLED=1
DD_FEATURE_FLAGS_CONFIGURATION_SOURCE=agentless
DD_FEATURE_FLAGS_CONFIGURATION_SOURCE_AGENTLESS_POLL_INTERVAL_SECONDS=1
DD_FEATURE_FLAGS_CONFIGURATION_SOURCE_AGENTLESS_REQUEST_TIMEOUT_SECONDS=1
DD_EXPERIMENTAL_FLAGGING_PROVIDER_INITIALIZATION_TIMEOUT_MS=10000
DD_EXPERIMENTAL_FLAGGING_PROVIDER_ENABLED=0
DD_TRACE_ENABLED=0
DD_TRACE_CLI_ENABLED=1
DD_REMOTE_CONFIG_ENABLED=0
DD_INSTRUMENTATION_TELEMETRY_ENABLED=0
DD_METRICS_OTEL_ENABLED=0
DD_TRACE_AGENT_URL=http://127.0.0.1:1
NO_PROXY=127.0.0.1,localhost
--FILE--
<?php
$scenario = 'threads';
require __DIR__ . '/stubs/agentless_process.inc';
?>
--EXPECT--
thread_readers_refreshed
thread_readers_shared_blue
