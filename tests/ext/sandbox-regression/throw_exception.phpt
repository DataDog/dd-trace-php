--TEST--
[Sandbox regression] Traced userland function catches and rethrows exception
--FILE--
<?php
function test($param = 2){
    throw new RuntimeException("FUNCTION " . $param);
}

DDTrace\trace_function("test", function($s, $a, $r, $e){
    echo "EXCEPTION IN HOOK " . $e->getMessage() . PHP_EOL;
});

try {
    test();
    test(1);
} catch (\Exception $e) {
    echo "EXCEPTION IN " . $e->getMessage() . PHP_EOL;
}

try {
    test(1);
} catch (\Exception $e) {
    echo "EXCEPTION IN " . $e->getMessage() . PHP_EOL;
}

?>
--EXPECT--
EXCEPTION IN HOOK FUNCTION 2
EXCEPTION IN FUNCTION 2
EXCEPTION IN HOOK FUNCTION 1
EXCEPTION IN FUNCTION 1
