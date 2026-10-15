--TEST--
DD_TRACE_AGENT_PROTOCOL_VERSION=0.4 keeps the sidecar on /v0.4/traces although the agent advertises /v1.0/traces
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
DD_TRACE_AGENT_PROTOCOL_VERSION=0.4
--INI--
datadog.trace.agent_test_session_token=serializer_wire_sidecar_forced_v04
--FILE--
<?php
include __DIR__ . '/../includes/request_replayer.inc';
$rr = new RequestReplayer();
$rr->clearDumpedData();

// Without the setting the sidecar switches to v1 once its /info fetch completes (well within 3s).
$v04 = $v1 = 0;
for ($i = 0; $i < 30; $i++) {
    $s = \DDTrace\start_span();
    $s->name = "root";
    \DDTrace\close_span();
    dd_trace_internal_fn("synchronous_flush");
    usleep(100000);
    foreach (($rr->replayAllRequests() ?: []) as $r) {
        if (strpos($r["uri"], "/v1.0/traces") !== false) $v1++;
        if (strpos($r["uri"], "/v0.4/traces") !== false) $v04++;
    }
}
echo "v0.4 requests: ", $v04 > 0 ? "some" : "none", "\n";
echo "v1 requests: $v1\n";
?>
--EXPECT--
v0.4 requests: some
v1 requests: 0
