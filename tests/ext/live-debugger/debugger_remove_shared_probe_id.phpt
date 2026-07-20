--TEST--
Removing live debugger configs that share a probe id must not leave a dangling hook
--SKIPIF--
<?php include __DIR__ . '/../includes/skipif_no_dev_env.inc'; ?>
--ENV--
DD_AGENT_HOST=request-replayer
DD_TRACE_AGENT_PORT=80
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_REMOTE_CONFIG_POLL_INTERVAL_SECONDS=0.1
--INI--
datadog.trace.agent_test_session_token=live-debugger/remove_shared_probe_id
--FILE--
<?php

require __DIR__ . "/live_debugger.inc";

reset_request_replayer();

function foo() {
    $span = \DDTrace\active_span();
    return $span ? $span->name : "removed";
}

put_dynamic_config_file([
    "dynamic_instrumentation_enabled" => true,
]);

// Two configs at different paths sharing the SAME probe id. Keying the hook map
// by probe id would orphan a hook on removal (use-after-free); distinct non-empty
// tags also make the paths differ and exercise the tags allocation freed on drop.
function put_span_probe_id1($tag) {
    return put_live_debugger_file([
        "id" => "1",
        "language" => "php",
        "evaluateAt" => "EXIT",
        "type" => "SPAN_PROBE",
        "where" => ["methodName" => "foo"],
        "tags" => [$tag],
    ]);
}

$p1 = put_span_probe_id1("a");
$p2 = put_span_probe_id1("b");

// Wait for BOTH probes to install, without calling foo() (keeps them pre-EMITTING
// so the diagnostics read of the probe id happens on the first call, after removal).
await_probe_installation(function () {}, 2);

// Remove both configs and let the remote-config poll process the removals.
del_rc_file($p1);
del_rc_file($p2);
for ($i = 0; $i < 300; $i++) {
    usleep(10000);
}

// No hook must remain: an orphaned one would read freed memory here.
var_dump(foo());

?>
--EXPECT--
string(7) "removed"
