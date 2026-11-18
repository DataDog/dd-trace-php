--TEST--
Removing a live debugger line probe, including while its range is open, and adding it back
--SKIPIF--
<?php include __DIR__ . '/../includes/skipif_no_dev_env.inc'; ?>
--ENV--
DD_AGENT_HOST=request-replayer
DD_TRACE_AGENT_PORT=80
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_DYNAMIC_INSTRUMENTATION_ENABLED=1
DD_REMOTE_CONFIG_POLL_INTERVAL_SECONDS=0.1
DD_DYNAMIC_INSTRUMENTATION_CAPTURE_TIMEOUT_MS=5000
DD_TRACE_AGENT_TEST_SESSION_TOKEN=live-debugger/line_probe_remove
--FILE--
<?php

require __DIR__ . "/live_debugger.inc";

reset_request_replayer();

// Loaded configs are keyed by config id, the path segment after the product.
function removed($path) {
    return !isset(dd_trace_internal_fn("get_loaded_remote_configs")[explode("/", $path)[3]]);
}

function in_range($while_open = null) {
    $value = 1; // line:open
    if ($while_open) {
        $while_open();
    }
    return $value; // line:close
}

$file = basename(__FILE__);
$range = marker_line(__FILE__, "open") . "-" . marker_line(__FILE__, "close");
$single = (string)marker_line(__FILE__, "open");

list($range_path, $single_path) = await_probe_installation(function () use ($file, $range, $single) {
    return [
        build_log_probe(["where" => ["sourceFile" => $file, "lines" => [$range]], "captureSnapshot" => true, "segments" => [["str" => "removed while open"]]]),
        build_log_probe(["where" => ["sourceFile" => $file, "lines" => [$single]], "segments" => [["str" => "removed"]]]),
    ];
}, 2);
\DDTrace\start_span(); // submit span data

// Removed before its line runs.
del_rc_file($single_path);
await_remote_config(function () use ($single_path) { return removed($single_path); });

// Removed while its range is open: the end still arrives, and drops the snapshot.
var_dump(in_range(function () use ($range_path) {
    del_rc_file($range_path);
    await_remote_config(function () use ($range_path) { return removed($range_path); });
}));
var_dump(in_range());

await_probe_installation(function () use ($file, $single) {
    build_log_probe(["where" => ["sourceFile" => $file, "lines" => [$single]], "segments" => [["str" => "added back"]]]);
});
var_dump(in_range());

$data = await_debugger_data(1);
foreach ($data["snapshots"] as $id => $logs) {
    foreach ($logs as $log) {
        echo "probe $id: {$log["message"]}\n";
    }
}

?>
--CLEAN--
<?php
require __DIR__ . "/live_debugger.inc";
reset_request_replayer();
?>
--EXPECT--
int(1)
int(1)
int(1)
probe 3: added back
