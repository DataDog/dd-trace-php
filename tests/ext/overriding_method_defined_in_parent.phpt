--TEST--
Check if we can override method from a parent class in a descendant class
--SKIPIF--
<?php if (PHP_VERSION_ID < 70000) die("skip: requires dd_trace support"); ?>
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
dd_trace("Test", "m", function() use ($no){
    return "HOOK " .  $this->m() . ' ' . $no . PHP_EOL;
});

dd_trace("Ancestor", "m2", function(){
    return  "HOOK " . $this->m2() . PHP_EOL;
});


echo (new Test())->m();
echo (new Test())->m2();

?>
--EXPECT--
HOOK METHOD 1
HOOK METHOD 2
