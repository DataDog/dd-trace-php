--TEST--
Disable tracing disables all tracing from happening
--SKIPIF--
<?php if (PHP_VERSION_ID < 70000) die("skip: requires dd_trace support"); ?>
--FILE--
<?php
class Test {
    public function m(){
        return 'METHOD' . PHP_EOL;
    }
}

dd_trace("Test", "m", function($arg){
    return 'HOOK ' . $this->m();
});

echo (new Test())->m("HOOK");
dd_trace_disable_in_request();
echo (new Test())->m("HOOK");

?>
--EXPECT--
HOOK METHOD
METHOD
