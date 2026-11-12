--TEST--
FFE stable and legacy source settings control native activation without configuration traffic
--SKIPIF--
<?php
if (PHP_VERSION_ID < 70200) die('skip: TEST_PHP_EXTRA_ARGS requires PHP 7.2+');
if (!function_exists('proc_open') || !function_exists('stream_socket_server')) die('skip: process and sockets required');
if (!getenv('TEST_PHP_EXECUTABLE')) die('skip: TEST_PHP_EXECUTABLE required');
?>
--ENV--
DD_TRACE_ENABLED=0
DD_TRACE_CLI_ENABLED=1
DD_REMOTE_CONFIG_ENABLED=0
DD_INSTRUMENTATION_TELEMETRY_ENABLED=0
DD_METRICS_OTEL_ENABLED=0
DD_TRACE_AGENT_URL=http://127.0.0.1:1
--FILE--
<?php
$cases = array(
    'default_disabled' => array(null, null, null, false, 'native_disabled'),
    'blank_disabled' => array(null, '   ', null, false, 'native_disabled'),
    'stable_no_source' => array('1', null, null, false, 'native_disabled'),
    'stable_empty_source' => array('1', '', null, false, 'native_disabled'),
    'stable_blank_source' => array('1', '   ', null, false, 'native_disabled'),
    'stable_over_legacy_true' => array('1', null, '1', false, 'native_disabled'),
    'stable_over_legacy_false' => array('1', null, '0', false, 'native_disabled'),
    'stable_kill' => array('0', 'agentless', '1', false, 'native_agentless'),
    'legacy_kill' => array(null, null, '0', false, 'native_remote_config'),
    'legacy_remote_config' => array(null, null, '1', true, 'native_remote_config'),
    'explicit_remote_config' => array(null, 'remote_config', '0', true, 'native_remote_config'),
    'stable_remote_config_kill' => array('0', 'remote_config', '1', false, 'native_remote_config'),
    'offline' => array('1', 'offline', '1', false, 'native_disabled'),
    'invalid' => array('1', 'unknown', '1', false, 'native_disabled'),
);
require __DIR__ . '/stubs/agentless_source_selection.inc';
?>
--EXPECT--
default_disabled=ok
blank_disabled=ok
stable_no_source=ok
stable_empty_source=ok
stable_blank_source=ok
stable_over_legacy_true=ok
stable_over_legacy_false=ok
stable_kill=ok
legacy_kill=ok
legacy_remote_config=ok
explicit_remote_config=ok
stable_remote_config_kill=ok
offline=ok
invalid=ok
