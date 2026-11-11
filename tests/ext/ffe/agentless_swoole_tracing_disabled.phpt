--TEST--
FFE agentless initializes and refreshes in Swoole workers with tracing disabled
--SKIPIF--
<?php
if (PHP_VERSION_ID < 70200) die('skip: TEST_PHP_EXTRA_ARGS requires PHP 7.2+');
if (!function_exists('proc_open') || !function_exists('stream_socket_server')) die('skip: process and sockets required');
if (!getenv('TEST_PHP_EXECUTABLE')) die('skip: TEST_PHP_EXECUTABLE required');
if (!extension_loaded('swoole') || version_compare(phpversion('swoole'), '5.0.2', '<')) die('skip: Swoole 5.0.2+ required');
?>
--ENV--
DD_FEATURE_FLAGS_ENABLED=1
DD_FEATURE_FLAGS_CONFIGURATION_SOURCE=agentless
DD_FEATURE_FLAGS_CONFIGURATION_SOURCE_AGENTLESS_POLL_INTERVAL_SECONDS=1
DD_FEATURE_FLAGS_CONFIGURATION_SOURCE_AGENTLESS_REQUEST_TIMEOUT_SECONDS=30
DD_EXPERIMENTAL_FLAGGING_PROVIDER_INITIALIZATION_TIMEOUT_MS=10000
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
foreach (array('0', '1') as $cold) {
    putenv('PHP_FFE_SWOOLE_COLD=' . $cold);
    $scenario = 'swoole';
    require __DIR__ . '/stubs/agentless_process.inc';
}
?>
--EXPECT--
child_forked
child_recovered
fork_clean_exit
parent_forked
parent_recovered
child_forked
child_recovered
fork_clean_exit
parent_forked
parent_recovered
