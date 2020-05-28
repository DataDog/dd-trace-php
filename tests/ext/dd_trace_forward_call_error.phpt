--TEST--
Error conditions for dd_trace_forward_call()
--SKIPIF--
<?php if (PHP_VERSION_ID < 70000) die("skip: requires dd_trace support"); ?>
--FILE--
<?php
// Out of closure context
try {
    dd_trace_forward_call();
} catch (\LogicException $e) {
    echo $e->getMessage() . "\n";
}

// Technically in closure context, but not in closure
function doStuff($foo)
{
    try {
        dd_trace_forward_call();
    } catch (\LogicException $e) {
        echo $e->getMessage() . "\n";
    }
    return '[' . $foo . ']';
}

dd_trace('doStuff', function () {
    echo "**TRACED**\n";
    return dd_trace_forward_call();
});

echo doStuff('Test') . "\n";
?>
--EXPECTF--
Cannot use dd_trace_forward_call() outside of a tracing closure
**TRACED**
Cannot use dd_trace_forward_call() outside of a tracing closure
[Test]
