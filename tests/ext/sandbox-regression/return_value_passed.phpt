--TEST--
[Sandbox regression] Return value passed to tracing closure
--DESCRIPTION--
This differs from the original dd_trace() test in that it does not modify the original call arguments
--FILE--
<?php
class Test {
    public function method(){
        return "original";
    }
}

$no = 1;
DDTrace\trace_method("Test", "method", function($span, array $args, $retval) use ($no){
    echo $retval . "-override ". $no . PHP_EOL;
});

(new Test())->method();

?>
--EXPECT--
original-override 1
