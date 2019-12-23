--TEST--
dd_trace() basic functionality (internal functions)
--FILE--
<?php
dd_trace('array_sum', function () {
    echo 'array_sum' . PHP_EOL;
    return dd_trace_forward_call();
});

dd_trace('mt_rand', function () {
    echo 'mt_rand' . PHP_EOL;
    return dd_trace_forward_call();
});

mt_rand();
for ($i = 0; $i < 10; $i++) {
    array_sum([]);
}
mt_rand();
mt_rand();
?>
--EXPECT--
mt_rand
array_sum
array_sum
array_sum
array_sum
array_sum
array_sum
array_sum
array_sum
array_sum
array_sum
mt_rand
mt_rand
