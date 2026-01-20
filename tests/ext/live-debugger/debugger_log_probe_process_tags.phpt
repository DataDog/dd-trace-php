--TEST--
Live debugger log probe includes process_tags
--SKIPIF--
<?php include __DIR__ . '/../includes/skipif_no_dev_env.inc'; ?>
--ENV--
DD_AGENT_HOST=request-replayer
DD_TRACE_AGENT_PORT=80
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_DYNAMIC_INSTRUMENTATION_ENABLED=1
DD_REMOTE_CONFIG_POLL_INTERVAL_SECONDS=0.01
DD_EXPERIMENTAL_PROPAGATE_PROCESS_TAGS_ENABLED=1
--INI--
datadog.trace.agent_test_session_token=live-debugger/log_probe_process_tags
--FILE--
<?php

require __DIR__ . "/live_debugger.inc";

reset_request_replayer();

function simple_function() {
    return "hello";
}

await_probe_installation(function() {
    build_log_probe([
        "where" => ["methodName" => "simple_function"],
        "captureSnapshot" => true,
        "segments" => [
            ["str" => "Simple message"],
        ],
    ]);

    \DDTrace\start_span(); // submit span data
});

simple_function();

$dlr = new DebuggerLogReplayer;
$log = $dlr->waitForDebuggerDataAndReplay();
$payload = json_decode($log["body"], true)[0];

if (isset($payload["process_tags"])) {
    echo "Process tags found in payload\n";
    $processTags = $payload["process_tags"];

    var_dump($processTags);
} else {
    echo "ERROR: process_tags not found in payload\n";
}

?>
--CLEAN--
<?php
require __DIR__ . "/live_debugger.inc";
reset_request_replayer();
?>
--EXPECTF--
Process tags found in payload
string(%d) "entrypoint.basedir:live-debugger,entrypoint.name:debugger_log_probe_process_tags,entrypoint.type:script,entrypoint.workdir:%s,runtime.sapi:cli"

