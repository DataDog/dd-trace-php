--TEST--
FFE AUTO fallback preserves the thread sidecar and rejects CDN without traffic
--SKIPIF--
<?php
if (PHP_VERSION_ID < 70200) die('skip: TEST_PHP_EXTRA_ARGS requires PHP 7.2+');
if (!function_exists('proc_open') || !function_exists('stream_socket_server')) die('skip: process and sockets required');
if (!getenv('TEST_PHP_EXECUTABLE')) die('skip: TEST_PHP_EXECUTABLE required');
?>
<?php if (PHP_OS !== 'Linux') die('skip: Linux abstract socket inspection required'); ?>
<?php if (getenv('USE_ZEND_ALLOC') === '0' && !getenv('SKIP_ASAN')) die('skip: valgrind incompatible with thread mode sidecar'); ?>
--ENV--
DD_TRACE_ENABLED=0
DD_TRACE_CLI_ENABLED=1
DD_REMOTE_CONFIG_ENABLED=0
DD_INSTRUMENTATION_TELEMETRY_ENABLED=0
DD_METRICS_OTEL_ENABLED=0
DD_TRACE_AGENT_URL=http://127.0.0.1:1
--FILE--
<?php
$cases = array('auto_thread_fallback' => array('1', 'agentless', '0', true, 'native_agentless'));
require __DIR__ . '/stubs/agentless_source_selection.inc';
?>
--EXPECT--
auto_thread_fallback=ok
