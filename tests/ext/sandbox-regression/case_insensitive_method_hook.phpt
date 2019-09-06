--TEST--
[Sandbox regression] Trace case-insensitive method from a child class
--SKIPIF--
<?php if (PHP_VERSION_ID < 50500) die('skip PHP 5.4 not supported'); ?>
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
dd_trace_method("Test", "methoD", function() use ($no){
    echo "HOOK " . $no . PHP_EOL;
});

(new Test())->MethOD();

?>
--EXPECT--
METHOD
HOOK 1
