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

// The sidecar sends v0.4 until its own /info fetch has advertised /v1.0/traces, and may batch
// several traces per payload: resend "root" until it shows up in any chunk of a v1 request.
$req = null;
for ($i = 0; $i < 100 && $req === null; $i++) {
    $s = \DDTrace\start_span();
    $s->name = "root";
    $s->service = "svc";
    \DDTrace\close_span();
    dd_trace_internal_fn("synchronous_flush");
    usleep(100000);
    foreach (($rr->replayAllRequests() ?: []) as $r) {
        if (strpos($r["uri"], "/v1.0/traces") === false) continue;
        foreach ((json_decode($r["body"], true)["chunks"] ?? []) as $chunk) {
            foreach (($chunk["spans"] ?? []) as $span) {
                if (($span["name"] ?? null) === "root" && ($span["service"] ?? null) === "svc") $req = $r;
            }
        }
    }
}
$root = json_decode($req["body"] ?? "null", true);
echo "uri=" . ($req["uri"] ?? "none") . "\n";
echo "has_chunks=" . (isset($root['chunks']) ? "yes" : "no") . "\n";
echo "span_name=" . ($req ? "root" : "?") . "\n";
?>
--EXPECT--
uri=/v1.0/traces
has_chunks=yes
span_name=root
