--TEST--
Check user defined function can be overriden and we're able to call the original
--ENV--
DD_TRACE_WARN_LEGACY_DD_TRACE=0
--FILE--
<?php
function test(){
    return "FUNCTION";
}

dd_trace("test", function(){
    return test() . ' HOOK' . PHP_EOL;
});

echo test();

?>
--EXPECT--
FUNCTION HOOK
