--TEST--
[Sandbox regression] Traced functions and methods are untraced with reset
--FILE--
<?php
class Test {
    public function m(){
        echo "METHOD" . PHP_EOL;
    }
}

DDTrace\trace_method("Test", "m", function(){
    echo "METHOD HOOK" . PHP_EOL;
});

function test(){
    echo "FUNCTION" . PHP_EOL;
}

DDTrace\trace_function("test", function(){
    echo "FUNCTION HOOK" . PHP_EOL;
});

$object = new Test();
$object->m();
test();

echo (dd_trace_reset() ? "TRUE": "FALSE") . PHP_EOL;

// Cannot call a function while it is not traced and later expect it to trace
//$object->m();
//test();

DDTrace\trace_method("Test", "m", function(){
    echo "METHOD HOOK2" . PHP_EOL;
});

DDTrace\trace_function("test", function(){
    echo "FUNCTION HOOK2" . PHP_EOL;
});

$object->m();
test();

?>
--EXPECT--
METHOD
METHOD HOOK
FUNCTION
FUNCTION HOOK
TRUE
METHOD
METHOD HOOK2
FUNCTION
FUNCTION HOOK2
