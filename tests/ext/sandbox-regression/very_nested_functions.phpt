--TEST--
[Sandbox regression] Trace deeply-nested function calls
--DESCRIPTION--
This differs from the original dd_trace() test in that the original return value is not modified
--SKIPIF--
<?php if (PHP_VERSION_ID < 70000) die("skip: zend_execute_ex in use"); ?>
--FILE--
<?php
function test($a){
    return 'FUNCTION ' . $a;
}

dd_trace_function("test", function($s, $a, $retval){
    echo 'HOOK ' . $retval . PHP_EOL;
});


function callNested($nestLevel, $counter){
    if ($nestLevel > 0) {
        // call another hooked function in the middle of the stack to check for possible edgecases
        if ($nestLevel == 50000) {
            return test(callNested($nestLevel - 1, $counter + 1));
        } else {
            return callNested($nestLevel - 1, $counter + 1);
        }
    } else {
        return test($counter);
    }
}

echo callNested(100000, 0) . PHP_EOL;

?>
--EXPECT--
HOOK FUNCTION 100000
HOOK FUNCTION FUNCTION 100000
FUNCTION FUNCTION 100000
