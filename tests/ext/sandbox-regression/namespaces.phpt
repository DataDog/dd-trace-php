--TEST--
[Sandbox regression] Namespaced functions and methods are traced
--DESCRIPTION--
This differs from the original dd_trace() test in that the original call is always forwarded before the tracing closure is called
--SKIPIF--
<?php if (PHP_VERSION_ID < 50500) die('skip PHP 5.4 not supported'); ?>
--FILE--
<?php
namespace Func {
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
}

namespace Klass {
    class Test {
        public function m($c, $end){
            echo "METHOD START " . $c .  PHP_EOL;
            \Func\test(1, 2);
            \Func\test_va(1,2);

            if ($c < $end) {
                $this->m($c + 1, $end);
            }
            echo "METHOD END " . $c .  PHP_EOL;
        }
        public function m2(){
            $this->m(func_get_arg(0), func_get_arg(1));
        }
    }

    dd_trace_method("Klass\\Test", "m", function($s, array $args){
        echo "M HOOK START " . $args[0] . " END " . $args[1] . PHP_EOL;
    });
}

namespace {
    dd_trace_function("Func\\test", function($s, array $args){
        echo "F HOOK START " . $args[0] . " END " . $args[1] . PHP_EOL;
    });

    dd_trace_function("Func\\test_va", function($s, array $args){
        echo "FVA HOOK START " . $args[0] . " END " . $args[1] . PHP_EOL;
    });

    dd_trace_method("Klass\\Test", "m2", function($s, array $args){
        echo "M2 HOOK START " . $args[0] . " END " . $args[1] . PHP_EOL;
    });

    Func\test(1, 3);
    echo PHP_EOL;
    Func\test_va(1,3);
    echo PHP_EOL;
    (new Klass\Test())->m(1,3);
    echo PHP_EOL;
    $f = function() {
        (new Klass\Test())->m2(2,3);
    };
    $f();
}
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
M2 HOOK START 2 END 3
