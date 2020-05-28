--TEST--
The original function call is invoked from the closure
--SKIPIF--
<?php if (PHP_VERSION_ID < 70000) die("skip: requires dd_trace support"); ?>
--FILE--
<?php
function doStuff($foo, array $bar = [])
{
    return '[' . $foo . '] ' . array_sum($bar);
}

// Cannot call a function while it is not traced and later expect it to trace
//echo doStuff('Before', [1, 2]) . "\n";

dd_trace('doStuff', function () {
    echo "**TRACED**\n";
    return dd_trace_forward_call();
});

echo doStuff('After', [2, 3]) . "\n";
?>
--EXPECT--
**TRACED**
[After] 5
