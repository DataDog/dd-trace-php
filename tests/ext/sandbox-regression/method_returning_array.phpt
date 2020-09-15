--TEST--
[Sandbox regression] Method can be traced and called from tracing closure
--FILE--
<?php
class Test {
    public function m($arg){
        return [$arg];
    }
}

DDTrace\trace_method("Test", "m", function($s, $args, $retval){
    echo implode(PHP_EOL, array_merge(
        (new Test())->m("METHOD"),
        $retval
    ));
});

(new Test())->m("HOOK");

?>
--EXPECT--
METHOD
HOOK
