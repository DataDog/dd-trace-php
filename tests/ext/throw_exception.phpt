--TEST--
Check user defined function can safely catch and rethrow exception
--FILE--
<?php
function test($param){
    throw new RuntimeException("FUNCTION " . $param);
}

dd_trace("test", function($param){
    try {
        test($param);
    } catch (\Exception $e) {
        echo "EXCEPTION IN HOOK " . $e->getMessage() . PHP_EOL;
        throw $e;
    } finally {
        echo "FINALLY" . PHP_EOL;
    }
});

try {
    test("1");
} catch (\Exception $e) {
    echo "EXCEPTION IN " . $e->getMessage() . PHP_EOL;
} finally {
    echo "HOOK" . PHP_EOL;
}

?>
--EXPECT--
EXCEPTION IN HOOK FUNCTION 1
FINALLY
EXCEPTION IN FUNCTION 1
HOOK
