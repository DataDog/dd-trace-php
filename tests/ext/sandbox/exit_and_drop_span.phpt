--TEST--
Exit gracefully handles a dropped span
--FILE--
<?php
DDTrace\trace_function('foo', function () {
    echo 'Dropping span' . PHP_EOL;
    return false;
});

function foo() {
    echo 'foo()' . PHP_EOL;
    exit;
}

foo();

echo 'You should not see this.' . PHP_EOL;
?>
--EXPECT--
foo()
Dropping span
