--TEST--
Live debugger line probes can be removed during tracer request shutdown, after the line hooks are gone
--SKIPIF--
<?php include __DIR__ . '/../includes/skipif_no_dev_env.inc'; ?>
--ENV--
DD_AGENT_HOST=request-replayer
DD_TRACE_AGENT_PORT=80
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_DYNAMIC_INSTRUMENTATION_ENABLED=1
DD_REMOTE_CONFIG_POLL_INTERVAL_SECONDS=0.1
DD_TRACE_AGENT_TEST_SESSION_TOKEN=live-debugger/line_probe_rshutdown
--INI--
datadog.autofinish_spans=1
--FILE--
<?php

require __DIR__ . "/live_debugger.inc";

reset_request_replayer();

function instrumented() {
    return 1; // line:probe
}

await_probe_installation(function () {
    build_log_probe(["where" => ["sourceFile" => basename(__FILE__), "lines" => [(string)marker_line(__FILE__, "probe")]], "segments" => [["str" => "hit"]]]);
});

// Left open, so tracer RSHUTDOWN closes it after tearing down the line hooks.
$span = \DDTrace\start_span();
$span->onClose[] = function () {
    // Disabling dynamic instrumentation removes every probe, including the line probe whose hooks are already released.
    var_dump(ini_set("datadog.dynamic_instrumentation.enabled", "0"));
    echo "onClose completed\n";
};

echo "request body complete\n";

?>
--CLEAN--
<?php
require __DIR__ . "/live_debugger.inc";
reset_request_replayer();
?>
--EXPECT--
request body complete
string(1) "1"
onClose completed
