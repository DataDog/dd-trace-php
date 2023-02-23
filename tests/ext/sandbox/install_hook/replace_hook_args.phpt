--TEST--
Overriding function arguments via install_hook()
--FILE--
<?php

function foo($a, $b) {
    print_r(func_get_args());
}

$hook = DDTrace\install_hook("foo", function($hook) {
    $hook->overrideArguments([5, 6, 7, 8]);
});

foo(1, 2, 3, 4, 5, 6); // more args
foo(1, 2, 3, 4); // equal args
foo(1, 2); // less args (fails)

DDTrace\install_hook("foo", function ($hook) {
    $hook->overrideArguments([0]); // less than required args, fails
});
DDTrace\remove_hook($hook);
foo(1, 2);

function optArg($a, $b = 3) {
    print_r(func_get_args());
    var_dump($a, $b);
}

DDTrace\install_hook("optArg", function ($hook) {
    $hook->overrideArguments([5]);
});
optArg(1, 2);

?>
--EXPECTF--
Array
(
    [0] => 5
    [1] => 6
    [2] => 7
    [3] => 8
)
Array
(
    [0] => 5
    [1] => 6
    [2] => 7
    [3] => 8
)
Cannot set more args than provided: got too many arguments for hook in %s:%d
Array
(
    [0] => 1
    [1] => 2
)
Not enough args provided for hook in %s:%d
Array
(
    [0] => 1
    [1] => 2
)
Can't pass less args to an untyped function than originally passed (minus extra args) in %s:%d
Array
(
    [0] => 1
    [1] => 2
)
int(1)
int(2)
