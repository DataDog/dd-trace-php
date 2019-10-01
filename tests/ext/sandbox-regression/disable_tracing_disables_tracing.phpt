--TEST--
[Sandbox regression] Disable tracing disables all tracing from happening
--SKIPIF--
<?php if (PHP_VERSION_ID < 50500) die('skip PHP 5.4 not supported'); ?>
--FILE--
<?php
class Test {
    public function m() {
        return 'METHOD' . PHP_EOL;
    }
}

dd_trace_method("Test", "m", function() {
    echo 'HOOK ';
});

echo (new Test())->m();
dd_trace_disable_in_request();
echo (new Test())->m();

?>
--EXPECT--
HOOK METHOD
METHOD
