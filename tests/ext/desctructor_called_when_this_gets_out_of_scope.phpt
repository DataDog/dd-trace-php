--TEST--
Test if desctructor is called when variable goes out of scope
--FILE--
<?php

class Test {
    function m() {
        return "M";
    }
    function __destruct()
    {
        echo "DESTRUCT" . PHP_EOL;
    }
}

dd_trace("Test", "m", function() {
    return dd_trace_forward_call() . " OVERRIDE";
});
function fn() {
    $test = new Test();
    echo $test->m() . PHP_EOL;
    echo "FN" . PHP_EOL;
}

fn();
echo "FINISH" . PHP_EOL;
?>

--EXPECT--
M OVERRIDE
FN
DESTRUCT
FINISH
