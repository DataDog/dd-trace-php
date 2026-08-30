--TEST--
[profiling] combined build registers only the ddtrace extension identity
--SKIPIF--
<?php
if (!extension_loaded('ddtrace') || extension_loaded('datadog-profiling'))
    die('skip: combined ddtrace build only');
if (ini_get('datadog.profiling.enabled') === false)
    die('skip: ddtrace was built without profiling support');
?>
--FILE--
<?php
var_dump(extension_loaded('ddtrace'));
var_dump(extension_loaded('datadog-profiling'));
var_dump((new ReflectionExtension('ddtrace'))->getName());
var_dump(in_array('ddtrace', get_loaded_extensions(true), true));
var_dump(in_array('datadog-profiling', get_loaded_extensions(true), true));
?>
--EXPECT--
bool(true)
bool(false)
string(7) "ddtrace"
bool(true)
bool(false)
