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

// Two live-debugger configs at different paths that carry the SAME probe id.
// Each config gets its own installed hook; the tracer must track and remove them
// independently. A spans_map keyed by probe id (instead of per-config) would let
// the second install overwrite the first, orphaning a hook whose backing config
// is freed on removal -> use-after-free when it later fires.
function put_span_probe_id1($variant) {
    return put_live_debugger_file([
        "id" => "1",
        "language" => "php",
        "evaluateAt" => "EXIT",
        "type" => "SPAN_PROBE",
        "where" => ["methodName" => "foo"],
        // Unknown field (ignored by the parser) only to make the two configs live
        // at distinct remote-config paths while carrying the same probe id.
        "_variant" => $variant,
    ]);
}

$p1 = put_span_probe_id1("a");
$p2 = put_span_probe_id1("b");

// Wait for installation WITHOUT calling foo(), so the probe stays pre-EMITTING
// and the diagnostics read of the probe id is deferred to the first call below.
await_probe_installation(function () {}, 1);

// Remove both configs, then let the remote-config poll process the removals.
del_rc_file($p1);
del_rc_file($p2);
for ($i = 0; $i < 300; $i++) {
    usleep(10000);
}

// With the configs gone, no hook must remain: foo() runs its (now uninstalled)
// probe exactly once here. If a hook was orphaned, this reads freed memory.
var_dump(foo());

?>
--EXPECT--
string(7) "removed"
