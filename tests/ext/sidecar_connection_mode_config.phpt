--TEST--
DD_TRACE_SIDECAR_CONNECTION_MODE configuration parsing
--SKIPIF--
<?php if (PHP_VERSION_ID < 70000) die('skip: PHP 7.0+ required'); ?>
--ENV--
DD_TRACE_SIDECAR_CONNECTION_MODE=subprocess
--FILE--
<?php

echo "Test 1: subprocess mode\n";
var_dump(ini_get('datadog.trace.sidecar_connection_mode'));

?>
--EXPECT--
Test 1: subprocess mode
string(10) "subprocess"
