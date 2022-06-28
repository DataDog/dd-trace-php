--TEST--
[Prehook Regression] Exit gracefully handles a dropped span
--FILE--
<?php
DDTrace\trace_function('foo', ['prehook' => function () {
    echo 'Dropping span' . PHP_EOL;
    return false;
}]);

function foo() {
    echo 'foo()' . PHP_EOL;
    exit;
}

foo();

echo 'You should not see this.' . PHP_EOL;
?>
--EXPECT--
Dropping span
foo()
