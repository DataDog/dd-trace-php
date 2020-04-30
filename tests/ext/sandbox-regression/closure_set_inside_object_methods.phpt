--TEST--
[Sandbox regression] Tracing closure set from inside non-static method
--DESCRIPTION--
This differs from the original dd_trace() test in that it does not modify the original call arguments
--SKIPIF--
<?php if (PHP_VERSION_ID < 50500) die('skip PHP 5.4 not supported'); ?>
--FILE--
<?php
class Test {
    public function m($v){
        echo "METHOD " . $v . PHP_EOL;
    }
}

$variable = 1000;

final class TestSetup {
    public function setup(){
        dd_trace_method("Test", "m", function($span, array $args) {
            $variable = $args[0] + 10;
            echo "HOOK " . $variable . PHP_EOL;
        });
    }
    public function setup_ext($j){
        dd_trace_method("Test", "m", function($span, array $args) use ($j){
            global $variable;
            $variable += $args[0] + $j;
            echo "HOOK " . $variable . PHP_EOL;
        });
    }
}

// Cannot call a function while it is not traced and later expect it to trace
//(new Test())->m(0);

// use convoluted way to execute to test if it also works
$o = new TestSetup();
$reflectionMethod = new ReflectionMethod('TestSetup', 'setup');
$reflectionMethod->invoke($o);

(new Test())->m(1);

$o->setup_ext(100);

(new Test())->m(1);
(new Test())->m(10);

?>
--EXPECT--
METHOD 1
HOOK 11
METHOD 1
HOOK 1101
METHOD 10
HOOK 1211
