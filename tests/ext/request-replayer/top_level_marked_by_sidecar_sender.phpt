--TEST--
The sidecar sender marks _dd.top_level (local root and service changes) without stats computation
--SKIPIF--
<?php
include __DIR__ . '/../includes/skipif_no_dev_env.inc';
if (getenv('USE_ZEND_ALLOC') === '0' && !getenv('SKIP_ASAN')) die('skip timing sensitive test - valgrind is too slow');
?>
--ENV--
DD_TRACE_LOG_LEVEL=error,startup=off
DD_AGENT_HOST=request-replayer
DD_TRACE_AGENT_PORT=80
DD_TRACE_AGENT_FLUSH_INTERVAL=333
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_INSTRUMENTATION_TELEMETRY_ENABLED=0
DD_TRACE_SIDECAR_TRACE_SENDER=1
DD_TRACE_STATS_COMPUTATION_ENABLED=0
--INI--
datadog.trace.agent_test_session_token=top_level_marked_by_sidecar_sender
--FILE--
<?php
include __DIR__ . '/../includes/request_replayer.inc';
$rr = new RequestReplayer();
$rr->clearDumpedData();

$root = \DDTrace\start_span();
$root->name = "root";
$root->service = "svc";
$same = \DDTrace\start_span();
$same->name = "child_same_service";
$same->service = "svc";
\DDTrace\close_span();
$diff = \DDTrace\start_span();
$diff->name = "child_other_service";
$diff->service = "other";
\DDTrace\close_span();
\DDTrace\close_span();
dd_trace_internal_fn("synchronous_flush");

$found = null;
$rr->waitForRequest(function ($r) use (&$found) {
    if (strpos($r["uri"], "/traces") === false) {
        return false;
    }
    $body = json_decode($r["body"], true);
    // v1: chunks of spans with attributes; v0.4: traces of spans with metrics.
    foreach (isset($body["chunks"]) ? array_column($body["chunks"], "spans") : $body as $spans) {
        if (in_array("child_other_service", array_column($spans, "name"), true)) {
            $found = $spans;
        }
    }
    return $found !== null;
});
$marks = [];
foreach ($found as $span) {
    $marks[$span["name"]] = isset($span["attributes"]["_dd.top_level"]) || isset($span["metrics"]["_dd.top_level"]) ? "1" : "not set";
}
ksort($marks);
foreach ($marks as $name => $mark) {
    echo "$name: _dd.top_level=$mark\n";
}
?>
--EXPECT--
child_other_service: _dd.top_level=1
child_same_service: _dd.top_level=not set
root: _dd.top_level=1
