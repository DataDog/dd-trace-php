--TEST--
Composite attributes under meta_struct roots are msgpack-encoded into meta_struct on the v0.4 wire (sidecar sender)
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
datadog.trace.agent_test_session_token=span_meta_struct_attributes_v04_wire_sidecar
--FILE--
<?php
include __DIR__ . '/../includes/request_replayer.inc';
include __DIR__ . '/../includes/meta_struct_attributes.inc';
$rr = new RequestReplayer();
$rr->clearDumpedData();

// The sidecar may batch several traces per payload: resend until the span shows up.
$found = null;
$deadline = microtime(true) + 40; // wall-clock cap: a synchronous flush can take seconds under load
while ($found === null && microtime(true) < $deadline) {
    meta_struct_attributes_span();
    dd_trace_internal_fn("synchronous_flush");
    usleep(100000);
    foreach (($rr->replayAllRequests() ?: []) as $r) {
        if (strpos($r["uri"], "/v0.4/traces") === false) continue;
        foreach ((json_decode($r["body"], true) ?: []) as $trace) {
            foreach ($trace as $span) {
                if (($span["service"] ?? null) === "meta-struct-svc") {
                    $found = $span;
                }
            }
        }
    }
}
meta_struct_attributes_dump_v04($found ?? []);
?>
--EXPECT--
meta_struct._dd.stack={"exploit":[{"frames":[{"file":"a.php","line":13}],"id":"e1"}]}
meta_struct.api.security.schema={"k":[1,"a"]}
meta_struct.appsec={"triggers":[{"rule":"r"}]}
meta.appsec.events.u="x"
meta.appsec.fingerprint.http.h="v"
meta.appsec.json.j="y"
meta.iast.s="x"
metrics.apisecurity.x=1
