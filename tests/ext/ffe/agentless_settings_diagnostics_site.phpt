--TEST--
FFE reports redacted invalid site diagnostics exactly once on first use
--SKIPIF--
<?php
if (PHP_VERSION_ID < 70200) die('skip: TEST_PHP_EXTRA_ARGS requires PHP 7.2+');
if (!function_exists('proc_open') || !getenv('TEST_PHP_EXECUTABLE')) die('skip: PHP child process required');
?>
--ENV--
DD_TRACE_ENABLED=0
DD_TRACE_CLI_ENABLED=1
DD_REMOTE_CONFIG_ENABLED=0
DD_INSTRUMENTATION_TELEMETRY_ENABLED=0
DD_METRICS_OTEL_ENABLED=0
DD_TRACE_STARTUP_LOGS=0
DD_TRACE_AGENT_URL=http://127.0.0.1:1
--FILE--
<?php
$caseName = 'site';
require __DIR__ . '/stubs/agentless_settings_diagnostics.inc';
?>
--EXPECT--
site=ok
