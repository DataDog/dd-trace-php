--TEST--
DDTrace\hook_function posthook exception is sandboxed (debug internal)
--ENV--
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_TRACE_LOG_LEVEL=info,startup=off
DD_TRACE_TRACED_INTERNAL_FUNCTIONS=array_sum
--INI--
zend.assertions=1
assert.exception=1
--FILE--
<?php

DDTrace\hook_function('array_sum',
    null,
    function ($args, $retval) {
        echo "array_sum hooked.\n";
        assert($args == [[1, 3]]);
        assert($retval === 4);
        throw new Exception('!');
    }
);

try {
    $sum = array_sum([1, 3]);
    echo "Sum = {$sum}.\n";
} catch (Exception $e) {
    echo "Exception caught.\n";
}

?>
--EXPECTF--
array_sum hooked.
[ddtrace] [warning] Exception thrown in ddtrace's closure defined at %s:%d for array_sum(): ! in %s on line %d
Sum = 4.
[ddtrace] [info] Flushing trace of size 1 to send-queue for %s
