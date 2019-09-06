--TEST--
[Sandbox regression] Method can be traced and called from tracing closure
--SKIPIF--
<?php if (PHP_VERSION_ID < 50500) die('skip PHP 5.4 not supported'); ?>
--FILE--
<?php
class Test {
    public function m($arg){
        return [$arg];
    }
}

dd_trace_method("Test", "m", function($s, $args, $retval){
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
