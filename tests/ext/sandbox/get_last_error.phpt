--TEST--
Existing errors are kept
--SKIPIF--
<?php if (PHP_VERSION_ID < 50500) die('skip PHP 5.4 not supported'); ?>
--INI--
ddtrace.traced_internal_functions=array_sum
--FILE--
<?php

@$i = $i_do_not_exist;
$last_error = error_get_last();
if (
    is_array($last_error)
    && $last_error['type'] == E_NOTICE
    && strpos($last_error['message'], 'Undefined variable') === 0
) {
    dd_trace_function('array_sum', function () {
        echo $i_also_do_not_exist;
    });
    array_sum([]);
    $current_error = error_get_last();
    var_dump($current_error === $last_error);
} else {
    echo "Test setup was not as expected\n";
}

?>
--EXPECTF--
bool(true)
