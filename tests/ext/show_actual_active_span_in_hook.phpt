--TEST--
Test hooking preserves the active span
--FILE--
<?php

function toHook() {
}

$rootSpan = DDTrace\active_span();
DDTrace\hook_function('toHook', function() use ($rootSpan) {
    var_dump($rootSpan == DDTrace\active_span());
});

toHook();

?>
--EXPECT--
bool(true)
