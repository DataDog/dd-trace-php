--TEST--
Exceptions in the tracing closure callback are always defined
--SKIPIF--
<?php if (PHP_VERSION_ID < 50500) die('skip PHP 5.4 not supported'); ?>
--INI--
ddtrace.traced_internal_functions=array_sum
--FILE--
<?php

var_dump(error_get_last());
dd_trace_function('array_sum', function ($span, $args, $retval, $ex) {
    var_dump(\is_null($ex));
    var_dump(error_get_last());
});
array_sum([]);
?>
--EXPECTF--
NULL
bool(true)
NULL
