--TEST--
Installing a live debugger span probe on a class
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

class Bar {
    static function foo() {
        $span = \DDTrace\active_span();
        return "{$span->name} {$span->resource}";
    }
}

function delayed() {
    class Delayed {
        static function foo() {
            $span = \DDTrace\active_span();
            return "{$span->name} {$span->resource}";
        }
    }
}

await_probe_installation(function() {
    build_span_probe(["where" => ["typeName" => "Bar", "methodName" => "foo"]]);
    build_span_probe(["where" => ["typeName" => "Delayed", "methodName" => "foo"]]);

    \DDTrace\start_span(); // submit span data
});

var_dump(Bar::foo());

delayed();
var_dump(Delayed::foo());

$dlr = new DebuggerLogReplayer;
$log = $dlr->waitForDiagnosticsDataAndReplay();
var_dump($log["uri"]);
var_dump($log["files"]["event"]["name"]);
$ordered = [];
foreach (json_decode($log["files"]["event"]["contents"], true) as $payload) {
    $diagnostic = $payload["debugger"]["diagnostics"];
    $ordered[$diagnostic["probeId"]][] = $diagnostic["status"];
}
ksort($ordered);
foreach ($ordered as $id => $statuses) {
    print "$id: " . implode(", ", $statuses) . "\n";
}

?>
--CLEAN--
<?php
require __DIR__ . "/live_debugger.inc";
reset_request_replayer();
?>
--EXPECTF--
string(23) "dd.dynamic.span Bar.foo"
string(27) "dd.dynamic.span Delayed.foo"
string(%d) "/debugger/v1/diagnostics?ddtags=debugger_version:%s,env:none,version:,runtime_id:%s,host_name:%s"
string(10) "event.json"
1: INSTALLED, EMITTING
2: RECEIVED, INSTALLED, EMITTING
