--TEST--
[Sandbox regression] Trace deeply-nested function calls (PHP 7)
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
