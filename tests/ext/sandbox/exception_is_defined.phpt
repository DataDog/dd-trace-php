--TEST--
Exceptions in the tracing closure callback are always defined
--ENV--
DD_TRACE_TRACED_INTERNAL_FUNCTIONS=array_sum
--FILE--
<?php

var_dump(error_get_last());
DDTrace\trace_function('array_sum', function ($span, $args, $retval, $ex) {
    var_dump(\is_null($ex));
    var_dump(error_get_last());
});
array_sum([]);
?>
--EXPECTF--
NULL
bool(true)
NULL
