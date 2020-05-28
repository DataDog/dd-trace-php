--TEST--
Check if we can safely override function being called deep in the call stack
--SKIPIF--
<?php if (PHP_VERSION_ID < 70000) die("skip: requires dd_trace support"); ?>
--FILE--
<?php
function test($a){
    return 'FUNCTION ' . $a;
}

dd_trace("test", function($a){
    return 'OLD HOOK ' . test($a);
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
OLD HOOK FUNCTION OLD HOOK FUNCTION 100000
