--TEST--
[Sandbox regression] Tracing closures do not run when extension is disabled
--INI--
ddtrace.disable=true
datadog.trace.sidecar_trace_sender=0
--FILE--
<?php
function test(){
    return "FUNCTION";
}

DDTrace\trace_function("test", function($s, $a, $retval){
    echo $retval . ' HOOK' . PHP_EOL;
});

var_dump(dd_trace_internal_fn('set_writer_send_on_flush', false));
echo test();

?>
--EXPECT--
bool(false)
FUNCTION
