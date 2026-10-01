--TEST--
analytics.event maps to the _dd1.sr.eausr metric on the v0.4 wire
--SKIPIF--
<?php
if (strncasecmp(PHP_OS, "WIN", 3) == 0) die('skip: the in-process sender is not available on Windows');
include __DIR__ . '/../includes/skipif_no_dev_env.inc';
// Pre-seed a non-v1 /info for this session token so the in-process sender downgrades to v0.4.
$ctx = stream_context_create(['http' => [
    'method' => 'PUT',
    'header' => ["Content-Type: application/json", "X-Datadog-Test-Session-Token: analytics_event_v04"],
    'content' => json_encode(["endpoints" => ["/v0.4/traces", "/v0.6/stats", "/v0.7/config"], "client_drop_p0s" => false, "version" => "7.66.0"]),
]]);
if (@file_get_contents("http://request-replayer/set-agent-info", false, $ctx) === false) {
    die("skip: request-replayer not reachable");
}
?>
--ENV--
DD_TRACE_LOG_LEVEL=error,startup=off
DD_AGENT_HOST=request-replayer
DD_TRACE_AGENT_PORT=80
DD_TRACE_AGENT_FLUSH_INTERVAL=333
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_INSTRUMENTATION_TELEMETRY_ENABLED=0
DD_TRACE_SIDECAR_TRACE_SENDER=0
--INI--
datadog.trace.agent_test_session_token=analytics_event_v04
--FILE--
<?php
include __DIR__ . '/../includes/request_replayer.inc';
$rr = new RequestReplayer();
$rr->clearDumpedData();

dd_trace_internal_fn('await_agent_info');

$s = \DDTrace\start_span();
$s->name = "root";
$s->meta["analytics.event"] = "true";
$c = \DDTrace\start_span();
$c->name = "child";
$c->attributes["analytics.event"] = 0;
\DDTrace\close_span();
\DDTrace\close_span();
dd_trace_internal_fn("synchronous_flush");

$req = $rr->waitForRequest(function ($r) { return strpos($r["uri"], "traces") !== false; });
echo "uri=" . $req["uri"] . "\n";
$spans = json_decode($req["body"], true)[0];
usort($spans, function ($a, $b) { return strcmp($a["name"], $b["name"]); });
foreach ($spans as $span) {
    echo $span["name"], ": eausr=", var_export($span["metrics"]["_dd1.sr.eausr"] ?? null, true),
        " analytics.event=", isset($span["meta"]["analytics.event"]) || isset($span["metrics"]["analytics.event"]) ? "kept" : "removed", "\n";
}
?>
--EXPECT--
uri=/v0.4/traces
child: eausr=0 analytics.event=removed
root: eausr=1 analytics.event=removed
