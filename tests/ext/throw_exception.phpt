--TEST--
Check user defined function can safely catch and rethrow exception
--FILE--
<?php
function test(){
    throw new RuntimeException("FUNCTION");
}

dd_trace("test", function(){
    try {
        test();
    } catch (\Exception $e) {
        echo "EXCEPTION IN HOOK " . $e->getMessage() . PHP_EOL;
        throw $e;
    } finally {
        echo "FINALLY" . PHP_EOL;
    }
});

try {
    test();
} catch (\Exception $e) {
    echo "EXCEPTION IN " . $e->getMessage() . PHP_EOL;
} finally {
    echo "HOOK" . PHP_EOL;
}

?>
--EXPECT--
EXCEPTION IN HOOK FUNCTION
FINALLY
EXCEPTION IN FUNCTION
HOOK
