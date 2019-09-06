--TEST--
[Sandbox regression] Multiple functions and methods are traced
--DESCRIPTION--
This differs from the original dd_trace() test in that it does not modify the original call arguments
--SKIPIF--
<?php if (PHP_VERSION_ID < 50500) die('skip PHP 5.4 not supported'); ?>
--FILE--
<?php
function test_a($a){
    echo "FUNCTION A " . $a . PHP_EOL;
}
function test_b($a){
    test_a($a);
    echo "FUNCTION B " . $a . PHP_EOL;
}
function test_c($a){
    test_b($a);
    echo "FUNCTION C " . $a . PHP_EOL;
}
function test_d($a){
    test_c($a);
    echo "FUNCTION d " . $a . PHP_EOL;
}

class Test {
    public function m_a($a){
        echo "METHOD A " . $a .  PHP_EOL;
    }
    public function m_b($a){
        $this->m_a($a);
        echo "METHOD B " . $a .  PHP_EOL;
    }
    public function m_c($a){
        $this->m_b($a);
        echo "METHOD C " . $a .  PHP_EOL;
    }
    public function m_d($a){
        $this->m_c($a);
        echo "METHOD D " . $a .  PHP_EOL;
    }
}

dd_trace_function("test_a", function(){
    echo "HOOK A" . PHP_EOL;
});

dd_trace_function("test_b", function(){
    echo "HOOK B" . PHP_EOL;
});

dd_trace_function("test_c", function(){
    echo "HOOK C" . PHP_EOL;
});

dd_trace_function("test_d", function(){
    echo "HOOK D" . PHP_EOL;
});

dd_trace_method("Test", "m_a", function(){
    echo "HOOK MA" . PHP_EOL;
});
dd_trace_method("Test", "m_b", function(){
    echo "HOOK MB" . PHP_EOL;
});
dd_trace_method("Test", "m_c", function(){
    echo "HOOK MC" . PHP_EOL;
});
dd_trace_method("Test", "m_d", function(){
    echo "HOOK MD" . PHP_EOL;
});

test_d(1);
test_d(2);

(new Test())->m_d(1);
(new Test())->m_d(-10);

?>
--EXPECT--
FUNCTION A 1
HOOK A
FUNCTION B 1
HOOK B
FUNCTION C 1
HOOK C
FUNCTION d 1
HOOK D
FUNCTION A 2
HOOK A
FUNCTION B 2
HOOK B
FUNCTION C 2
HOOK C
FUNCTION d 2
HOOK D
METHOD A 1
HOOK MA
METHOD B 1
HOOK MB
METHOD C 1
HOOK MC
METHOD D 1
HOOK MD
METHOD A -10
HOOK MA
METHOD B -10
HOOK MB
METHOD C -10
HOOK MC
METHOD D -10
HOOK MD
