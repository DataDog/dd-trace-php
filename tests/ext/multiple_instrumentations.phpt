--TEST--
Verify Multiple functions and methods will be instrumented successfully
--SKIPIF--
<?php if (PHP_VERSION_ID < 70000) die("skip: requires dd_trace support"); ?>
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

dd_trace("test_a", function($a){
    test_a($a+1);
    echo "HOOK A" . PHP_EOL;
});

dd_trace("test_b", function($a){
    test_b($a+1);
    echo "HOOK B" . PHP_EOL;
});

dd_trace("test_c", function($a){
    test_c($a+1);
    echo "HOOK C" . PHP_EOL;
});

dd_trace("test_d", function($a){
    test_d($a+1);
    echo "HOOK D" . PHP_EOL;
});

dd_trace("Test", "m_a", function($a){
    $this->m_a($a+1);
    echo "HOOK MA" . PHP_EOL;
});
dd_trace("Test", "m_b", function($a){
    $this->m_b($a+1);
    echo "HOOK MB" . PHP_EOL;
});
dd_trace("Test", "m_c", function($a){
    $this->m_c($a+1);
    echo "HOOK MC" . PHP_EOL;
});
dd_trace("Test", "m_d", function($a){
    $this->m_d($a+1);
    echo "HOOK MD" . PHP_EOL;
});

test_d(1);
test_d(2);

(new Test())->m_d(1);
(new Test())->m_d(-10);

?>
--EXPECT--

FUNCTION A 5
HOOK A
FUNCTION B 4
HOOK B
FUNCTION C 3
HOOK C
FUNCTION d 2
HOOK D
FUNCTION A 6
HOOK A
FUNCTION B 5
HOOK B
FUNCTION C 4
HOOK C
FUNCTION d 3
HOOK D
METHOD A 5
HOOK MA
METHOD B 4
HOOK MB
METHOD C 3
HOOK MC
METHOD D 2
HOOK MD
METHOD A -6
HOOK MA
METHOD B -7
HOOK MB
METHOD C -8
HOOK MC
METHOD D -9
HOOK MD
