--TEST--
Disable tracing disables all tracing from happening
--ENV--
DD_TRACE_WARN_LEGACY_DD_TRACE=0
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
