--TEST--
Override arguments of a function with default arguments
--FILE--
<?php

function bar($a = []) {
    print_r(func_get_args());
}

DDTrace\install_hook("bar", function ($hook) {
    $hook->overrideArguments([[42]]);
});

bar();

bar(["a string"]);

function barNumber($a = 0) {
    print_r(func_get_args());
}

DDTrace\install_hook("barNumber", function ($hook) {
    $hook->overrideArguments([42]);
});

barNumber();

class Foo {
    public function echoFoo() {
        echo "Foo";
    }
}

class Bar {
    public function echoBar() {
        echo "Bar";
    }
}

function barClass($a = Foo::class) {
    print_r(func_get_args());
}

DDTrace\install_hook("barClass", function ($hook) {
    $hook->overrideArguments([Bar::class]);
});

barClass();

function everything($a, $b = 0, $c = [], $d = Foo::class) {
    print_r(func_get_args());
}

DDTrace\install_hook("everything", function ($hook) {
    $hook->overrideArguments([42, 42, [42], Bar::class]);
});

everything(1);

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
Array
(
    [0] => 42
)
Array
(
    [0] => Bar
)
Array
(
    [0] => 42
    [1] => 42
    [2] => Array
        (
            [0] => 42
        )

    [3] => Bar
)