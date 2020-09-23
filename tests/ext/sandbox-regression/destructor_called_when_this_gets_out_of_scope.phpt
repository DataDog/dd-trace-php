--TEST--
[Sandbox regression] Destructor is called when object goes out of scope
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

DDTrace\trace_method("Test", "m", function($s, $a, $retval) {
    echo $retval . " OVERRIDE" . PHP_EOL;
});
function func() {
    $test = new Test();
    $test->m();
    echo "FUNC" . PHP_EOL;
}

func();
echo "FINISH" . PHP_EOL;
?>

--EXPECT--
M OVERRIDE
FUNC
DESTRUCT
FINISH
