--TEST--
dd_trace() basic functionality (userland functions)
--FILE--
<?php
function myFunc1($foo) {
    return $foo;
}

function myFunc2($bar) {
    return $bar;
}

dd_trace('myFunc1', function () {
    echo 'myFunc1' . PHP_EOL;
    return dd_trace_forward_call();
});

dd_trace('myFunc2', function () {
    echo 'myFunc2' . PHP_EOL;
    return dd_trace_forward_call();
});

myFunc2('foo');
for ($i = 0; $i < 10; $i++) {
    myFunc1([]);
}
myFunc2(42);
myFunc2(true);
?>
--EXPECT--
myFunc2
myFunc1
myFunc1
myFunc1
myFunc1
myFunc1
myFunc1
myFunc1
myFunc1
myFunc1
myFunc1
myFunc2
myFunc2
