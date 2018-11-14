--TEST--
Check if closure can safely use variable names also present in outside scope
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
        dd_trace("Test", "m", function($i) {
            $variable = $i + 10;
            $this->m($variable);
            echo "HOOK " . $variable . PHP_EOL;
        });
    }
    public function setup_ext($j){
        dd_trace("Test", "m", function($i) use ($j){
            global $variable;
            $variable += $i + $j;
            $this->m($variable);
            echo "HOOK " . $variable . PHP_EOL;
        });
    }
}


(new Test())->m(0);

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
METHOD 0
METHOD 11
HOOK 11
METHOD 1101
HOOK 1101
METHOD 1211
HOOK 1211
