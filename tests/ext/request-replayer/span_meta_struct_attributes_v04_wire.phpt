--TEST--
Composite attributes under meta_struct roots are msgpack-encoded into meta_struct on the v0.4 wire (in-process sender)
--SKIPIF--
<?php
if (strncasecmp(PHP_OS, "WIN", 3) == 0) die('skip: the in-process sender is not available on Windows');
include __DIR__ . '/../includes/skipif_no_dev_env.inc';
// Pre-seed a non-v1 /info for this session token so the in-process sender downgrades to v0.4.
$ctx = stream_context_create(['http' => [
    'method' => 'PUT',
    'header' => ["Content-Type: application/json", "X-Datadog-Test-Session-Token: span_meta_struct_attributes_v04_wire"],
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
datadog.trace.agent_test_session_token=span_meta_struct_attributes_v04_wire
--FILE--
<?php
include __DIR__ . '/../includes/request_replayer.inc';
include __DIR__ . '/../includes/meta_struct_attributes.inc';
$rr = new RequestReplayer();
$rr->clearDumpedData();

dd_trace_internal_fn('await_agent_info');

meta_struct_attributes_span();
dd_trace_internal_fn("synchronous_flush");

$req = $rr->waitForRequest(function ($r) { return strpos($r["uri"], "traces") !== false; });
echo "uri=" . $req["uri"] . "\n";
meta_struct_attributes_dump_v04(json_decode($req["body"], true)[0][0]);
?>
--EXPECT--
uri=/v0.4/traces
meta_struct._dd.stack={"exploit":[{"frames":[{"file":"a.php","line":13}],"id":"e1"}]}
meta_struct.api.security.schema={"k":[1,"a"]}
meta_struct.appsec={"triggers":[{"rule":"r"}]}
meta.appsec.events.u="x"
meta.appsec.fingerprint.http.h="v"
meta.appsec.json.j="y"
meta.iast.s="x"
metrics.apisecurity.x=1
