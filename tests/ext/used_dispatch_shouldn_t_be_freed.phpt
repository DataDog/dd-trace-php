--TEST--
Check if we can safely override instrumentation from within instrumentation.
--FILE--
<?php
function test($a){
    dd_trace("test", function($a){
        return 'NEW HOOK ' . test($a);
    });
    return 'METHOD ' . $a;
}

dd_trace("test", function($a){
    return 'OLD HOOK ' . test($a);
});

echo test("exec_a") . PHP_EOL;
echo test("exec_b") . PHP_EOL;

?>
--EXPECT--
OLD HOOK METHOD exec_a
NEW HOOK METHOD exec_b
