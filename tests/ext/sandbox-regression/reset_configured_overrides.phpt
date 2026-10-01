--TEST--
[Sandbox regression] Re-tracing a function/method adds an additional hook (hooks stack)
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
METHOD
METHOD HOOK2
METHOD HOOK
FUNCTION
FUNCTION HOOK2
FUNCTION HOOK
