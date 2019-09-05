--TEST--
[Sandbox regression] Recursive calls will trace only outermost invocation
--DESCRIPTION--
This differs from the original dd_trace() test in that the original call is always forwarded before the tracing closure is called
--SKIPIF--
<?php if (PHP_VERSION_ID < 50500) die('skip PHP 5.4 not supported'); ?>
--FILE--
<?php
function test($c, $end){
    echo "FUNCTION START " . $c . PHP_EOL;

    if ($c < $end) {
        test($c + 1, $end);
    }
    echo "FUNCTION EXIT " . $c . PHP_EOL;
}

function test_va(){
    test(func_get_arg(0), func_get_arg(1));
}

class Test {
    public function m($c, $end){
        echo "METHOD START " . $c .  PHP_EOL;
        test(1, 2);
        test_va(1, 2);

        if ($c < $end) {
            $this->m($c + 1, $end);
        }
        echo "METHOD END " . $c .  PHP_EOL;
    }
}

dd_trace_function("test", function($s, array $args){
    echo "F HOOK START " . $args[0] . " END " . $args[1] . PHP_EOL;
});

dd_trace_function("test_va", function($s, array $args){
    echo "FVA HOOK START " . $args[0] . " END " . $args[1] . PHP_EOL;
});

dd_trace_method('Test', "m", function($s, array $args){
    echo "M HOOK START " . $args[0] . " END " . $args[1] . PHP_EOL;
});

test(1, 3);
echo PHP_EOL;
test_va(1,3);
echo PHP_EOL;
(new Test())->m(1,3);
echo PHP_EOL;
$f = function() {
    (new Test())->m(2,3);
};
$f();
?>
--EXPECT--
FUNCTION START 1
FUNCTION START 2
FUNCTION START 3
FUNCTION EXIT 3
FUNCTION EXIT 2
FUNCTION EXIT 1
F HOOK START 1 END 3

FUNCTION START 1
FUNCTION START 2
FUNCTION START 3
FUNCTION EXIT 3
FUNCTION EXIT 2
FUNCTION EXIT 1
F HOOK START 1 END 3
FVA HOOK START 1 END 3

METHOD START 1
FUNCTION START 1
FUNCTION START 2
FUNCTION EXIT 2
FUNCTION EXIT 1
F HOOK START 1 END 2
FUNCTION START 1
FUNCTION START 2
FUNCTION EXIT 2
FUNCTION EXIT 1
F HOOK START 1 END 2
FVA HOOK START 1 END 2
METHOD START 2
FUNCTION START 1
FUNCTION START 2
FUNCTION EXIT 2
FUNCTION EXIT 1
F HOOK START 1 END 2
FUNCTION START 1
FUNCTION START 2
FUNCTION EXIT 2
FUNCTION EXIT 1
F HOOK START 1 END 2
FVA HOOK START 1 END 2
METHOD START 3
FUNCTION START 1
FUNCTION START 2
FUNCTION EXIT 2
FUNCTION EXIT 1
F HOOK START 1 END 2
FUNCTION START 1
FUNCTION START 2
FUNCTION EXIT 2
FUNCTION EXIT 1
F HOOK START 1 END 2
FVA HOOK START 1 END 2
METHOD END 3
METHOD END 2
METHOD END 1
M HOOK START 1 END 3

METHOD START 2
FUNCTION START 1
FUNCTION START 2
FUNCTION EXIT 2
FUNCTION EXIT 1
F HOOK START 1 END 2
FUNCTION START 1
FUNCTION START 2
FUNCTION EXIT 2
FUNCTION EXIT 1
F HOOK START 1 END 2
FVA HOOK START 1 END 2
METHOD START 3
FUNCTION START 1
FUNCTION START 2
FUNCTION EXIT 2
FUNCTION EXIT 1
F HOOK START 1 END 2
FUNCTION START 1
FUNCTION START 2
FUNCTION EXIT 2
FUNCTION EXIT 1
F HOOK START 1 END 2
FVA HOOK START 1 END 2
METHOD END 3
METHOD END 2
M HOOK START 2 END 3
