--TEST--
Sandbox API is not available on unsupported versions
--SKIPIF--
<?php if (PHP_VERSION_ID >= 50600) die('skip Test is only for versions that do not support the sandbox API'); ?>
--FILE--
<?php
var_dump(function_exists('DDTrace\trace_function'));
var_dump(function_exists('DDTrace\trace_method'));
?>
--EXPECT--
bool(false)
bool(false)
