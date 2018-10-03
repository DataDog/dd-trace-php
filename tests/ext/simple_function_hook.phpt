--TEST--
Check user defined function can be overriden and we're able to call the original
--FILE--
<?php
function test(){
    echo "FUNCTION" . PHP_EOL;
}

dd_trace("test", function(){
    test();
    echo "HOOK" . PHP_EOL;
});

test();

?>
--EXPECT--
FUNCTION
HOOK
