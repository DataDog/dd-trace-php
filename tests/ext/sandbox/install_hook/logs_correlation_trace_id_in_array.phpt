--TEST--
Overriding using arrays shouldn't create memory leaks
--FILE--
<?php

function bar($a = []) {
    print_r(func_get_args());
}

DDTrace\install_hook("bar", function ($hook) {
    $hook->overrideArguments([[42]]);
});

bar();

bar(["a string"])

?>
--EXPECT--
Array
(
    [0] => Array
        (
            [0] => 42
        )

)
Array
(
    [0] => Array
        (
            [0] => 42
        )

)
