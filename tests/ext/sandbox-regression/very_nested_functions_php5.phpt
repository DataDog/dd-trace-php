--TEST--
[Sandbox regression] Trace deeply-nested function calls [PHP 5]
--SKIPIF--
<?php if (PHP_VERSION_ID >= 70000) die("skip: PHP 7 has its own test"); ?>
--FILE--
<?php
function test($a){
    return 'FUNCTION ' . $a;
}

DDTrace\trace_function("test", function($s, $a, $retval){
    echo 'HOOK ' . $retval . PHP_EOL;
});


function callNested($nestLevel, $counter){
    if ($nestLevel > 0) {
        // call another hooked function in the middle of the stack to check for possible edgecases
        if ($nestLevel == 384) {
            return test(callNested($nestLevel - 1, $counter + 1));
        } else {
            return callNested($nestLevel - 1, $counter + 1);
        }
    } else {
        return test($counter);
    }
}

echo callNested(768, 0) . PHP_EOL;

?>
--EXPECTF--
ddtrace has detected a call stack depth of %d. If the call stack depth continues to grow the application may encounter a segmentation fault; see %s for details.
HOOK FUNCTION 768
HOOK FUNCTION FUNCTION 768
FUNCTION FUNCTION 768
