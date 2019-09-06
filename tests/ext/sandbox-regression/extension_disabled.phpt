--TEST--
[Sandbox regression] Tracing closures do not run when extension is disabled
--SKIPIF--
<?php if (PHP_VERSION_ID < 50500) die('skip PHP 5.4 not supported'); ?>
--INI--
ddtrace.disable=true
--FILE--
<?php
function test(){
    return "FUNCTION";
}

dd_trace_function("test", function($s, $a, $retval){
    echo $retval . ' HOOK' . PHP_EOL;
});

echo test();

?>
--EXPECT--
FUNCTION
