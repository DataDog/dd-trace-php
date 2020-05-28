--TEST--
Verify functions and methods can be overriden even when in namespaces.
--SKIPIF--
<?php if (PHP_VERSION_ID < 70000) die("skip: requires dd_trace support"); ?>
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

    dd_trace("Klass\\Test", "m", function($c, $end){
        echo "M HOOK START " . $c . PHP_EOL;
        $this->m($c, $end);
        echo "M HOOK END " . $c . PHP_EOL;
    });
}

namespace {
    dd_trace("Func\\test", function($c, $end){
        echo "F HOOK START " . $c . PHP_EOL;
        Func\test($c, $end);
        echo "F HOOK END " . $c . PHP_EOL;
    });

    dd_trace("Func\\test_va", function($c, $end){
        echo "FVA HOOK START " . $c . PHP_EOL;
        Func\test_va($c, $end);
        echo "FVA HOOK END " . $c . PHP_EOL;
    });

    dd_trace("Klass\\Test", "m2", function($c, $end){
        echo "M2 HOOK START " . $c . PHP_EOL;
        $this->m2($c, $end);
        echo "M2 HOOK END " . $c . PHP_EOL;
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

F HOOK START 1
FUNCTION START 1
FUNCTION START 2
FUNCTION START 3
FUNCTION EXIT 3
FUNCTION EXIT 2
FUNCTION EXIT 1
F HOOK END 1

FVA HOOK START 1
F HOOK START 1
FUNCTION START 1
FUNCTION START 2
FUNCTION START 3
FUNCTION EXIT 3
FUNCTION EXIT 2
FUNCTION EXIT 1
F HOOK END 1
FVA HOOK END 1

M HOOK START 1
METHOD START 1
F HOOK START 1
FUNCTION START 1
FUNCTION START 2
FUNCTION EXIT 2
FUNCTION EXIT 1
F HOOK END 1
FVA HOOK START 1
F HOOK START 1
FUNCTION START 1
FUNCTION START 2
FUNCTION EXIT 2
FUNCTION EXIT 1
F HOOK END 1
FVA HOOK END 1
METHOD START 2
F HOOK START 1
FUNCTION START 1
FUNCTION START 2
FUNCTION EXIT 2
FUNCTION EXIT 1
F HOOK END 1
FVA HOOK START 1
F HOOK START 1
FUNCTION START 1
FUNCTION START 2
FUNCTION EXIT 2
FUNCTION EXIT 1
F HOOK END 1
FVA HOOK END 1
METHOD START 3
F HOOK START 1
FUNCTION START 1
FUNCTION START 2
FUNCTION EXIT 2
FUNCTION EXIT 1
F HOOK END 1
FVA HOOK START 1
F HOOK START 1
FUNCTION START 1
FUNCTION START 2
FUNCTION EXIT 2
FUNCTION EXIT 1
F HOOK END 1
FVA HOOK END 1
METHOD END 3
METHOD END 2
METHOD END 1
M HOOK END 1

M2 HOOK START 2
M HOOK START 2
METHOD START 2
F HOOK START 1
FUNCTION START 1
FUNCTION START 2
FUNCTION EXIT 2
FUNCTION EXIT 1
F HOOK END 1
FVA HOOK START 1
F HOOK START 1
FUNCTION START 1
FUNCTION START 2
FUNCTION EXIT 2
FUNCTION EXIT 1
F HOOK END 1
FVA HOOK END 1
METHOD START 3
F HOOK START 1
FUNCTION START 1
FUNCTION START 2
FUNCTION EXIT 2
FUNCTION EXIT 1
F HOOK END 1
FVA HOOK START 1
F HOOK START 1
FUNCTION START 1
FUNCTION START 2
FUNCTION EXIT 2
FUNCTION EXIT 1
F HOOK END 1
FVA HOOK END 1
METHOD END 3
METHOD END 2
M HOOK END 2
M2 HOOK END 2
