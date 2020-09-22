--TEST--
Existing errors are kept
--ENV--
DD_TRACE_TRACED_INTERNAL_FUNCTIONS=array_sum
--FILE--
<?php

@$i = $i_do_not_exist;
$last_error = error_get_last();
$type = PHP_VERSION_ID < 80000 ? E_NOTICE : E_WARNING;
if (
    is_array($last_error)
    && $last_error['type'] === $type
    && strpos($last_error['message'], 'Undefined variable') === 0
) {
    DDTrace\trace_function('array_sum', function () {
        echo $i_also_do_not_exist;
    });
    array_sum([]);
    $current_error = error_get_last();
    var_dump($current_error === $last_error);
} else {
    echo "Test setup was not as expected\n";
}

?>
--EXPECT--
bool(true)
