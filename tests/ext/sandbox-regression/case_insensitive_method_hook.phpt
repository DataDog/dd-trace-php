--TEST--
[Sandbox regression] Trace case-insensitive method from a child class
--FILE--
<?php
class Ancestor {
    public function Method(){
        echo "METHOD" . PHP_EOL;
    }
}

class Test extends Ancestor{

}

$no = 1;
DDTrace\trace_method("Test", "methoD", function() use ($no){
    echo "HOOK " . $no . PHP_EOL;
});

(new Test())->MethOD();

?>
--EXPECT--
METHOD
HOOK 1
