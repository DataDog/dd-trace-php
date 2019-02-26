--TEST--
Check if we can override method from a parent class in a descendant class
--FILE--
<?php
class Ancestor {
    public function m(){
        echo "METHOD" . PHP_EOL;
    }
}

class Test extends Ancestor{

}

$no = 1;
dd_trace("Test", "m", function() use ($no){
    $this->m();
    echo "HOOK " . $no . PHP_EOL;
});

(new Test())->m();

?>
--EXPECT--
METHOD
HOOK 1
