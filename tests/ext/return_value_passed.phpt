--TEST--
Returs value from both original and overriding methods
--FILE--
<?php
class Test {
    public function method(){
        return "original";
    }
}

$no = 1;
dd_trace("Test", "method", function() use ($no){
    // $this->method();
    return  "-override ". PHP_EOL;
});

$a = (new Test())->method();
echo $a;

?>
--EXPECT--
original-override 1
