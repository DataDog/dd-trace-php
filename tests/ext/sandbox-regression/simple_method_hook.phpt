--TEST--
[Sandbox regression] Userland method is traced
--FILE--
<?php
class Test {
    public function m(){
        echo "METHOD" . PHP_EOL;
    }
}

DDTrace\trace_method("Test", "m", function(){
    echo "HOOK" . PHP_EOL;
});

(new Test())->m();

?>
--EXPECT--
METHOD
HOOK
