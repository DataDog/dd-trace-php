--TEST--
DDTrace\hook_function posthook exception is sandboxed (internal)
--ENV--
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
--EXPECT--
array_sum hooked.
Sum = 4.
