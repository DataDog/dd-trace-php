--TEST--
The original function call is invoked from the closure
--FILE--
<?php
function doStuff($foo, array $bar = [])
{
    return '[' . $foo . '] ' . array_sum($bar);
}

echo doStuff('Before', [1, 2]) . "\n";

dd_trace('doStuff', function () {
    echo "**TRACED**\n";
    return dd_trace_forward_call();
});

echo doStuff('After', [2, 3]) . "\n";
?>
--EXPECT--
[Before] 3
**TRACED**
[After] 5
