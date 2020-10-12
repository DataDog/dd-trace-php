--TEST--
[Sandbox regression] Tracing closure safely uses variables from outside scope
--FILE--
<?php
// variable present in outside scope
$variable = 1;

class Test {
    public function m(){
        echo "METHOD" . PHP_EOL;
    }
}

function setup($variable){
    DDTrace\trace_method("Test", "m", function() use ($variable){
        echo "HOOK " . $variable . PHP_EOL;
    });
}

// Cannot call a function while it is not traced and later expect it to trace
//(new Test())->m();
setup(1);
(new Test())->m();
setup(3);
(new Test())->m();

?>
--EXPECT--
METHOD
HOOK 1
METHOD
HOOK 3
