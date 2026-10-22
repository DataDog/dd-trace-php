--TEST--
[profiling] combined ddtrace defaults profiling to disabled
--SKIPIF--
<?php
if (!extension_loaded('ddtrace') || extension_loaded('datadog-profiling'))
    die('skip: combined ddtrace build only');
if (ini_get('datadog.profiling.enabled') === false)
    die('skip: ddtrace was built without profiling support');
?>
--FILE--
<?php
var_dump(ini_get('datadog.profiling.enabled'));
?>
--EXPECT--
string(1) "0"
