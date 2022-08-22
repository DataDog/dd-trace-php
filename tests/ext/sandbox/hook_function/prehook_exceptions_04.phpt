--TEST--
DDTrace\hook_function prehook exception is sandboxed (debug internal)
--ENV--
DD_TRACE_DEBUG=1
DD_TRACE_TRACED_INTERNAL_FUNCTIONS=array_sum
--INI--
zend.assertions=1
assert.exception=1
--FILE--
<?php

DDTrace\hook_function('array_sum',
    function ($args) {
        echo "array_sum hooked.\n";
        assert($args == [[1, 3]]);
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
Exception thrown in ddtrace's closure defined at %s:%d for array_sum(): !
Sum = 4.
Flushing trace of size 1 to send-queue for %s
