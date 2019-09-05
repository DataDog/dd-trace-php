--TEST--
[Sandbox regression] Traced userland function catches and rethrows exception
--SKIPIF--
<?php if (PHP_VERSION_ID < 50500) die('skip PHP 5.4 not supported'); ?>
--FILE--
<?php
function test($param = 2){
    throw new RuntimeException("FUNCTION " . $param);
}

dd_trace_function("test", function($s, $a, $r, $e){
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
