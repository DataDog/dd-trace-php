--TEST--
Exception in tracing closure gets logged
--ENV--
DD_TRACE_DEBUG=1
DD_TRACE_TRACED_INTERNAL_FUNCTIONS=array_sum
--FILE--
<?php
DDTrace\trace_function('array_sum', function () {
    throw new RuntimeException("This exception is expected");
});
$sum = array_sum([1, 3, 5]);
var_dump($sum);
?>
--EXPECTF--
RuntimeException thrown in ddtrace's closure defined at %s:%d for array_sum(): This exception is expected
int(9)
Successfully triggered flush with trace of size 2
