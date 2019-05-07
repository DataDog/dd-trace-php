--TEST--
Sanity check when extension is disabled
--INI--
ddtrace.disable=true
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
FUNCTION
