--TEST--
Composite attributes under meta_struct roots stay typed nested attributes on the V1 wire, and $meta_struct is not sent
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
datadog.trace.agent_test_session_token=span_meta_struct_attributes_v1_wire
--FILE--
<?php
include __DIR__ . '/../includes/request_replayer.inc';
include __DIR__ . '/../includes/meta_struct_attributes.inc';
$rr = new RequestReplayer();
$rr->clearDumpedData();

// The sidecar sends v0.4 until its own /info fetch has advertised /v1.0/traces, and may batch
// several traces per payload: resend until the span shows up in a v1 request.
$found = null;
$deadline = microtime(true) + 40; // wall-clock cap: a synchronous flush can take seconds under load
while ($found === null && microtime(true) < $deadline) {
    meta_struct_attributes_span();
    dd_trace_internal_fn("synchronous_flush");
    usleep(100000);
    foreach (($rr->replayAllRequests() ?: []) as $r) {
        if (strpos($r["uri"], "/v1.0/traces") === false) continue;
        foreach ((json_decode($r["body"], true)["chunks"] ?? []) as $chunk) {
            foreach (($chunk["spans"] ?? []) as $span) {
                if (($span["service"] ?? null) === "meta-struct-svc") {
                    $found = $span;
                }
            }
        }
    }
}
$attributes = meta_struct_attributes_sorted($found["attributes"] ?? []);
foreach ($attributes as $key => $value) {
    if (preg_match('/^(_dd\.stack|api\.|appsec|iast|apisecurity|ignored)/', $key)) {
        echo "$key=", json_encode($value), "\n";
    }
}
var_dump(isset($found["meta_struct"]));
?>
--EXPECT--
_dd.stack={"exploit":[{"frames":[{"file":"a.php","line":13}],"id":"e1"}]}
api.security.schema={"k":[1,"a"]}
apisecurity={"x":1}
appsec={"events":{"u":"x"},"triggers":[{"rule":"r"}]}
appsec.fingerprint.http={"h":"v"}
appsec.json={"j":"y"}
iast.s="x"
bool(false)
