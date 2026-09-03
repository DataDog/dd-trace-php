--TEST--
In-process sender ignores a v1-capable agent and still serializes the v0.4 wire (/v0.4/traces)
--DESCRIPTION--
Locks the in-process sender -> v0.4 wire mapping even when the agent advertises /v1.0/traces. The
in-process (<=8.2 / DD_TRACE_SIDECAR_TRACE_SENDER=0) sender ALWAYS downgrades the native V1 builder
to v0.4 and posts each trace to /v0.4/traces; a native V1 payload is a single msgpack MAP that the
background sender's array-of-1 framing (comms_php.c mpack_expect_array_match) cannot parse, so the
in-process path never uses /v1.0/traces regardless of agent capability.
--SKIPIF--
<?php
include __DIR__ . '/../includes/skipif_no_dev_env.inc';
if (getenv('USE_ZEND_ALLOC') === '0' && !getenv('SKIP_ASAN')) die('skip timing sensitive test - valgrind is too slow');
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
datadog.trace.agent_test_session_token=serializer_wire_inprocess_v1
--FILE--
<?php
include __DIR__ . '/../includes/request_replayer.inc';
$rr = new RequestReplayer();
$rr->clearDumpedData();

// The default request-replayer /info advertises /v1.0/traces; confirm the in-process sender
// still downgrades to v0.4.
dd_trace_internal_fn('await_agent_info');

$s = \DDTrace\start_span();
$s->name = "root";
$s->service = "svc";
\DDTrace\close_span();
dd_trace_internal_fn("synchronous_flush");

$req = $rr->waitForRequest(function ($r) { return strpos($r["uri"], "traces") !== false; });
$root = json_decode($req["body"], true);
$spans = $root["chunks"][0]["spans"] ?? $root[0];
echo "uri=" . $req["uri"] . "\n";
echo "has_chunks=" . (isset($root['chunks']) ? "yes" : "no") . "\n";
echo "span_name=" . $spans[0]["name"] . "\n";
?>
--EXPECTF--
[ddtrace] [info] [%d] Flushing %d v0.4 trace(s) to send-queue for http://request-replayer:80
uri=/v0.4/traces
has_chunks=no
span_name=root
[ddtrace] [info] [%d] No finished traces to be sent to the agent
