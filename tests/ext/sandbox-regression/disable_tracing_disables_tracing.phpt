--TEST--
[Sandbox regression] Disable tracing disables all tracing from happening
--FILE--
<?php
class Test {
    public function m() {
        return 'METHOD' . PHP_EOL;
    }
}

DDTrace\trace_method("Test", "m", function() {
    echo 'HOOK ';
});

echo (new Test())->m();
dd_trace_disable_in_request();
echo (new Test())->m();

?>
--EXPECT--
HOOK METHOD
METHOD
