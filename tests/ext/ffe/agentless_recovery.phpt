--TEST--
FFE agentless initialization deadline is shared across evaluations and failed initial polling recovers
--SKIPIF--
<?php
if (PHP_VERSION_ID < 70200) die('skip: TEST_PHP_EXTRA_ARGS requires PHP 7.2+');
if (!function_exists('proc_open') || !function_exists('stream_socket_server')) die('skip: process and sockets required');
if (!getenv('TEST_PHP_EXECUTABLE')) die('skip: TEST_PHP_EXECUTABLE required');
?>
--ENV--
DD_FEATURE_FLAGS_ENABLED=1
DD_FEATURE_FLAGS_CONFIGURATION_SOURCE=agentless
DD_FEATURE_FLAGS_CONFIGURATION_SOURCE_AGENTLESS_POLL_INTERVAL_SECONDS=1
DD_FEATURE_FLAGS_CONFIGURATION_SOURCE_AGENTLESS_REQUEST_TIMEOUT_SECONDS=30
DD_EXPERIMENTAL_FLAGGING_PROVIDER_INITIALIZATION_TIMEOUT_MS=200
DD_EXPERIMENTAL_FLAGGING_PROVIDER_ENABLED=0
DD_TRACE_ENABLED=0
DD_TRACE_CLI_ENABLED=1
DD_REMOTE_CONFIG_ENABLED=0
DD_INSTRUMENTATION_TELEMETRY_ENABLED=0
DD_METRICS_OTEL_ENABLED=0
DD_TRACE_AGENT_URL=http://127.0.0.1:1
DD_API_KEY=custom-endpoint-must-not-receive-this
NO_PROXY=127.0.0.1,localhost
--FILE--
<?php
$scenario = 'recovery';
require __DIR__ . '/stubs/agentless_process.inc';
?>
--EXPECT--
initial_deadline_bounded
initial_failure_recovered
later_evaluations_do_not_wait
