--TEST--
DDTrace\hook_function prehook exception is sandboxed (debug internal)
--SKIPIF--
<?php if (PHP_VERSION_ID >= 70000) die('skip: Test does not work with internal spans'); ?>
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
--EXPECT--
array_sum hooked.
Exception thrown in ddtrace's closure for array_sum(): !
Sum = 4.
