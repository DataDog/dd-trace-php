--TEST--
Check user defined function can safely catch and rethrow exception
--SKIPIF--
<?php if (PHP_VERSION_ID < 70000) die("skip: requires dd_trace support"); ?>
--FILE--
<?php
function test($param = 2){
    throw new RuntimeException("FUNCTION " . $param);
}

dd_trace("test", function($param = 2){
    try {
        test($param);
    } catch (\Exception $e) {
        echo "EXCEPTION IN HOOK " . $e->getMessage() . PHP_EOL;
        throw $e;
    }
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
