--TEST--
Sanity check when extension is disabled
--INI--
ddtrace.disable=true
--FILE--
<?php
function test(){
    return "FUNCTION";
}
error_reporting(E_ALL & ~E_DEPRECATED);
DDTrace\trace_function("test", function(){
    return test() . ' HOOK' . PHP_EOL;
});
error_reporting(E_ALL);

echo test();

?>
--EXPECT--
FUNCTION
