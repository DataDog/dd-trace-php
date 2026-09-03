--TEST--
Sidecar sender + v1-capable agent serializes the v1 wire (/v1.0/traces, chunks)
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
--INI--
datadog.trace.agent_test_session_token=serializer_wire_sidecar_v1
--FILE--
<?php
include __DIR__ . '/../includes/request_replayer.inc';
$rr = new RequestReplayer();
$rr->clearDumpedData();

// The sidecar decides the wire from its own /info negotiation, which can race the first
// flush; drive a few warm-up flushes until it has negotiated the v1 endpoint.
$uri = null;
for ($i = 0; $i < 30 && $uri === null; $i++) {
    \DDTrace\start_span();
    \DDTrace\close_span();
    dd_trace_internal_fn("synchronous_flush");
    usleep(100000);
    foreach (($rr->replayAllRequests() ?: []) as $r) {
        if (strpos($r["uri"], "/v1.0/traces") !== false) { $uri = "/v1.0/traces"; break; }
    }
    $rr->clearDumpedData();
}

$s = \DDTrace\start_span();
$s->name = "root";
$s->service = "svc";
\DDTrace\close_span();
dd_trace_internal_fn("synchronous_flush");

// Match the request carrying our "root" span, not a leftover warm-up trace (the warm-up spans
// are auto-named after the script and may still be queued behind the sidecar's flush).
$req = $rr->waitForRequest(function ($r) {
    if (strpos($r["uri"], "traces") === false) return false;
    $b = json_decode($r["body"], true);
    return (($b["chunks"][0]["spans"][0]["name"] ?? null) === "root");
});
$root = json_decode($req["body"], true);
echo "uri=" . $req["uri"] . "\n";
echo "has_chunks=" . (isset($root['chunks']) ? "yes" : "no") . "\n";
echo "span_name=" . ($root["chunks"][0]["spans"][0]["name"] ?? "?") . "\n";
?>
--EXPECT--
uri=/v1.0/traces
has_chunks=yes
span_name=root
