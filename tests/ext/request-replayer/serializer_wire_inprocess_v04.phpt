--TEST--
In-process sender + non-v1 agent serializes the v0.4 wire (/v0.4/traces, no chunks)
--SKIPIF--
<?php
include __DIR__ . '/../includes/skipif_no_dev_env.inc';
// Pre-seed a NON-v1 /info (no /v1.0/traces) for this session token so the in-process
// agent-info reader negotiates the v0.4 downgrade. Done in SKIPIF (separate process)
// so the reader never observes the default v1-advertising /info.
$ctx = stream_context_create(['http' => [
    'method' => 'PUT',
    'header' => ["Content-Type: application/json", "X-Datadog-Test-Session-Token: serializer_wire_v04"],
    'content' => json_encode(["endpoints" => ["/v0.4/traces", "/v0.6/stats", "/v0.7/config"], "client_drop_p0s" => false, "version" => "7.66.0"]),
]]);
if (@file_get_contents("http://request-replayer/set-agent-info", false, $ctx) === false) {
    die("skip: request-replayer not reachable");
}
?>
--ENV--
DD_TRACE_LOG_LEVEL=info,startup=off
DD_AGENT_HOST=request-replayer
DD_TRACE_AGENT_PORT=80
DD_TRACE_AGENT_FLUSH_INTERVAL=333
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_INSTRUMENTATION_TELEMETRY_ENABLED=0
DD_TRACE_SIDECAR_TRACE_SENDER=0
--INI--
datadog.trace.agent_test_session_token=serializer_wire_v04
--FILE--
<?php
include __DIR__ . '/../includes/request_replayer.inc';
$rr = new RequestReplayer();
$rr->clearDumpedData();

// Decide the wire before flushing (reads the non-v1 /info above).
dd_trace_internal_fn('await_agent_info');

$s = \DDTrace\start_span();
$s->name = "root";
$s->service = "svc";
$s->meta["span.kind"] = "server";
\DDTrace\close_span();
dd_trace_internal_fn("synchronous_flush");

$req = $rr->waitForRequest(function ($r) { return strpos($r["uri"], "traces") !== false; });
$root = json_decode($req["body"], true);
$spans = $root["chunks"][0]["spans"] ?? $root[0];
echo "uri=" . $req["uri"] . "\n";
echo "has_chunks=" . (isset($root['chunks']) ? "yes" : "no") . "\n";
echo "span_name=" . $spans[0]["name"] . "\n";
echo "priority=" . $spans[0]["metrics"]["_sampling_priority_v1"] . "\n";
echo "span_kind=" . $spans[0]["meta"]["span.kind"] . "\n";
?>
--EXPECTF--
[ddtrace] [info] [%d] Flushing %d v0.4 trace(s) to send-queue for http://request-replayer:80
uri=/v0.4/traces
has_chunks=no
span_name=root
priority=1
span_kind=server
[ddtrace] [info] [%d] No finished traces to be sent to the agent
