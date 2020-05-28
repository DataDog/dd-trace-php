--TEST--
Returns value from both original and overriding methods
--SKIPIF--
<?php if (PHP_VERSION_ID < 70000) die("skip: requires dd_trace support"); ?>
--FILE--
<?php
class Test {
    public function method(){
        return "original";
    }
}

$no = 1;
dd_trace("Test", "method", function() use ($no){
    return $this->method() . "-override ". $no . PHP_EOL;
});

$a = (new Test())->method();
echo $a;

?>
--EXPECT--
original-override 1
