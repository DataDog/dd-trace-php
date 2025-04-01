--TEST--
Exception in tracing closure gets logged
--ENV--
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_TRACE_LOG_LEVEL=info,startup=off
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
[ddtrace] [warning] RuntimeException thrown in ddtrace's closure defined at %s:%d for array_sum(): This exception is expected in %s on line %d
int(9)
[ddtrace] [info] Flushing trace of size 2 to send-queue for %s
