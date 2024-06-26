--TEST--
Installing a live debugger span probe
--SKIPIF--
<?php include __DIR__ . '/../includes/skipif_no_dev_env.inc'; ?>
--ENV--
DD_AGENT_HOST=request-replayer
DD_TRACE_AGENT_PORT=80
DD_TRACE_GENERATE_ROOT_SPAN=0
--FILE--
<?php

require __DIR__ . "/live_debugger.inc";

reset_request_replayer();

function foo() {
    return \DDTrace\active_span()->name;
}

await_probe_installation(function() {
    build_span_probe(["where" => ["methodName" => "foo"]]);

    \DDTrace\start_span(); // submit span data
});

var_dump(foo());

?>
--CLEAN--
<?php
require __DIR__ . "/live_debugger.inc";
reset_request_replayer();
?>
--EXPECT--
string(15) "dd.dynamic.span"
