--TEST--
Returs value from both original and overriding methods
--ENV--
DD_TRACE_WARN_LEGACY_DD_TRACE=0
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
