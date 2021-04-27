--TEST--
[Sandbox regression] Trace extended method when called from parent class
--FILE--
<?php
class Ancestor {
    public function m(){
        return "METHOD";
    }

    public function m2(){
        return "METHOD 2";
    }
}

class Test extends Ancestor{

}

$no = 1;
DDTrace\trace_method("Test", "m", function($s, $a, $retval) use ($no){
    echo "HOOK " .  $retval . ' ' . $no . PHP_EOL;
});

DDTrace\trace_method("Ancestor", "m2", function($s, $a, $retval){
    echo "HOOK " . $retval . PHP_EOL;
});


(new Test())->m();
(new Test())->m2();

?>
--EXPECT--
HOOK METHOD 1
HOOK METHOD 2
